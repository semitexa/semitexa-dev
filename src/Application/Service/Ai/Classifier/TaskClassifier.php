<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Classifier;

use Semitexa\Dev\Application\Service\Ai\Recipe\Recipe;
use Semitexa\Dev\Application\Service\Ai\Recipe\RecipeRegistry;

/**
 * Maps a one-line task description to the closest known {@see Recipe} using
 * a deterministic rule + keyword scorer. No ML, no embedding model — Phase 2
 * keeps this dumb-but-explainable so the agent can reason about why a recipe
 * was chosen.
 *
 * Scoring weights:
 *   verbs:    3 points per match (action verbs are the strongest signal)
 *   keywords: 2 points per match (domain nouns)
 *   bigrams:  1 point per shared 2-word substring with the recipe label
 *
 * Every result carries an honest {@see ClassificationResult::$confidence}:
 *   none — below the noise floor; recipe is the unknown_task placeholder.
 *   low  — above the floor but weak/ambiguous; clarify before executing.
 *   high — score ≥ 8 with a lead ≥ 3 over the runner-up (AGENTS.md §3 bar).
 * Callers should branch on $confidence, not re-derive the threshold from $score.
 */
final class TaskClassifier
{
    private const WEIGHT_VERB = 3;
    private const WEIGHT_KEYWORD = 2;
    private const WEIGHT_LABEL_BIGRAM = 1;
    private const NOISE_FLOOR = 2;
    private const UNKNOWN_RECIPE_ID = 'unknown_task';

    /** "fix broken dashboard widgets" is a bug report and "explain a wizard" a question, whatever nouns they name. */
    private const NOT_CREATING_VERBS = ['fix', 'repair', 'debug', 'rename', 'remove', 'delete', 'refactor', 'explain', 'describe', 'document'];
    private const CREATING_VERBS = ['build', 'create', 'add', 'make'];

    /** "build a board service" asks for the service, whatever it serves. */
    private const NAMED_ARTEFACTS = ['service', 'listener', 'console command'];

    /**
     * Reliability bar for confidence=high. Mirrors AGENTS.md §3's inline-eligibility
     * rule verbatim: "ai:task score ≥ 8 AND no alternative within 2 points" — i.e. a
     * lead of at least 3 over the next-best recipe. Anything above the noise floor
     * but below this bar is confidence=low: a best guess that must be clarified
     * before it drives execution, not an authoritative classification.
     */
    private const RELIABLE_SCORE = 8;
    private const RELIABLE_MARGIN = 3;

    public function classify(string $description, ?string $hintModule = null): ClassificationResult
    {
        $normalized = mb_strtolower(trim($description));
        $tokens = $this->tokenize($normalized);
        $bigrams = $this->bigrams($tokens);
        $leadingVerb = $this->leadingVerb($tokens);

        $scored = [];
        // What a recipe the task rules out would have scored: it cannot win, but it
        // still stands against the winner, so "a private endpoint that returns the
        // status" is a question, not a high-confidence guard (review, dev#240).
        $ruledOut = 0;
        foreach (RecipeRegistry::all() as $recipe) {
            if ($recipe->id === self::UNKNOWN_RECIPE_ID) {
                continue;
            }
            $score = $this->score($recipe, $tokens, $bigrams, $leadingVerb);
            if (array_intersect($recipe->excluded_by, [...$tokens, ...$bigrams]) !== []) {
                $ruledOut = max($ruledOut, $score);
                $score = 0;
            }
            $scored[] = ['recipe' => $recipe, 'score' => $score];
        }

        usort($scored, static fn(array $a, array $b): int => $b['score'] <=> $a['score']);

        /** @var array{recipe: Recipe, score: int} $top */
        $top = $scored[0];

        if ($top['score'] < self::NOISE_FLOOR) {
            $fallback = RecipeRegistry::find(self::UNKNOWN_RECIPE_ID);
            if ($fallback !== null) {
                return new ClassificationResult(
                    recipe: $fallback,
                    score: 0,
                    reason: 'no recipe matched — description did not hit a known verb or keyword. Proceed manually via ai:ask + Edit; do not trust the recipe field.',
                    suggested_module: $hintModule ?? $this->guessModule($description),
                    alternatives: [],
                    confidence: ClassificationResult::CONFIDENCE_NONE,
                );
            }
        }

        $secondScore = max($scored[1]['score'] ?? 0, $ruledOut);
        $confidence = $this->confidence($top['score'], $secondScore);
        $reason = $this->explain($top['recipe'], $tokens, $confidence);

        $alternatives = [];
        for ($i = 1, $n = count($scored); $i < $n; $i++) {
            if ($scored[$i]['score'] < self::NOISE_FLOOR) {
                break;
            }
            $alternatives[] = ['recipe_id' => $scored[$i]['recipe']->id, 'score' => $scored[$i]['score']];
            if (count($alternatives) >= 3) {
                break;
            }
        }

        return new ClassificationResult(
            recipe: $top['recipe'],
            score: $top['score'],
            reason: $reason,
            suggested_module: $hintModule ?? $this->guessModule($description),
            alternatives: $alternatives,
            confidence: $confidence,
        );
    }

    /**
     * Map a top score + its lead over the runner-up onto the honest confidence
     * band. high only when the doctrine's reliability bar is met; everything
     * else above the noise floor is a low-confidence guess.
     *
     * @return 'high'|'low'
     */
    private function confidence(int $top, int $second): string
    {
        if ($top >= self::RELIABLE_SCORE && ($top - $second) >= self::RELIABLE_MARGIN) {
            return ClassificationResult::CONFIDENCE_HIGH;
        }
        return ClassificationResult::CONFIDENCE_LOW;
    }

    /**
     * @param list<string> $tokens
     * @param list<string> $bigrams
     */
    private function score(Recipe $recipe, array $tokens, array $bigrams, ?string $leadingVerb): int
    {
        if ($recipe->noun_led
            && (in_array($leadingVerb, self::NOT_CREATING_VERBS, true) || array_intersect(self::NAMED_ARTEFACTS, [...$tokens, ...$bigrams]) !== [])) {
            return 0;
        }
        $score = 0;
        if ($recipe->noun_led
            && in_array($leadingVerb, self::CREATING_VERBS, true)
            && array_intersect($recipe->verbs, $tokens) !== []) {
            $score += self::WEIGHT_VERB;
        }
        foreach ($recipe->verbs as $verb) {
            if (in_array(strtolower($verb), $tokens, true)) {
                $score += self::WEIGHT_VERB;
            }
        }
        foreach ($recipe->keywords as $keyword) {
            if (in_array(strtolower($keyword), $tokens, true)) {
                $score += self::WEIGHT_KEYWORD;
            }
        }

        $labelBigrams = $this->bigrams($this->tokenize(strtolower($recipe->label)));
        foreach ($bigrams as $bg) {
            if (in_array($bg, $labelBigrams, true)) {
                $score += self::WEIGHT_LABEL_BIGRAM;
            }
        }
        return $score;
    }

    /**
     * The first verb says what is being asked; later ones describe the thing asked for.
     * "build a grid to delete rows" builds, and "delete the grid" does not.
     *
     * @param list<string> $tokens
     */
    private function leadingVerb(array $tokens): ?string
    {
        foreach ($tokens as $token) {
            if (in_array($token, self::CREATING_VERBS, true) || in_array($token, self::NOT_CREATING_VERBS, true)) {
                return $token;
            }
        }
        return null;
    }

    /**
     * @param list<string> $tokens
     * @param 'high'|'low' $confidence
     */
    private function explain(Recipe $recipe, array $tokens, string $confidence): string
    {
        $matchedVerbs = array_values(array_intersect(array_map('strtolower', $recipe->verbs), $tokens));
        $matchedKeywords = array_values(array_intersect(array_map('strtolower', $recipe->keywords), $tokens));

        $parts = [];
        if ($matchedVerbs !== []) {
            $parts[] = 'verbs(' . implode(',', $matchedVerbs) . ')';
        }
        if ($matchedKeywords !== []) {
            $parts[] = 'keywords(' . implode(',', $matchedKeywords) . ')';
        }
        $basis = $parts === []
            ? 'no strong signals — fell back to default ordering'
            : 'matched ' . implode(' + ', $parts);

        if ($confidence === ClassificationResult::CONFIDENCE_LOW) {
            return $basis . ' — low confidence: weak or ambiguous match, clarify the goal or confirm the recipe before executing (do not blindly run a generator).';
        }
        return $basis;
    }

    /**
     * Ukrainian stems → the English tokens the recipes are written in.
     *
     * The operator writes in Ukrainian, and the tokenizer used to split on
     * anything outside [a-z0-9], so a Ukrainian request produced no tokens and
     * always came back unknown_task. A stem matches as a word prefix, which
     * covers the case endings (сторінку, сторінці, сторінка). Short or
     * ambiguous words are listed whole instead.
     */
    private const UKRAINIAN_STEMS = [
        'дода' => ['add'], 'створ' => ['create'], 'виправ' => ['fix'], 'полагод' => ['fix'],
        'таблиц' => ['table'], 'сортув' => ['sort'], 'фільтр' => ['filter'], 'майстр' => ['wizard'], 'крок' => ['step'],
        'оновлюєт' => ['itself'], 'сторінк' => ['page'], 'шаблон' => ['template'], 'текст' => ['text'], 'одрук' => ['typo'],
        'помилк' => ['error'], 'падає' => ['failing'], 'зламан' => ['broken'],
        'чому' => ['why'], 'дослід' => ['investigate'], 'розслід' => ['investigate'],
        'команд' => ['command'], 'консол' => ['console'], 'консольн' => ['console'],
        'слухач' => ['listener'], 'підпиш' => ['subscribe'],
        'переймен' => ['rename'], 'рефактор' => ['refactor'], 'винес' => ['extract'], 'почист' => ['clean'],
        'сервіс' => ['service'], 'модул' => ['module'], 'контракт' => ['contract'], 'інтерфейс' => ['interface'],
        'ендпоінт' => ['endpoint'], 'ендпойнт' => ['endpoint'], 'маршрут' => ['route'],
        'повільн' => ['slow'], 'оптиміз' => ['optimize'], 'пришвидш' => ['speed'], 'кеш' => ['cache'],
        'аудит' => ['audit'], 'перевір' => ['check'], 'документ' => ['document'], 'поясн' => ['explain'],
        'мігр' => ['migrate'], 'міграц' => ['migration'],
    ];

    /** Words ending in "s" that are not a plural of anything the recipes use. */
    private const NOT_PLURALS = ['news', 'https', 'does', 'was', 'has', 'its'];

    private const UKRAINIAN_WORDS = [
        // As prefixes these would also catch багато ("many") and подивись ("look").
        'баг' => ['bug'], 'баги' => ['bug'], 'багу' => ['bug'], 'бага' => ['bug'],
        'подія' => ['event'], 'події' => ['event'], 'подію' => ['event'], 'подій' => ['event'],
        'поле' => ['field'], 'поля' => ['field'], 'пейлоад' => ['payload'],
    ];

    /**
     * @return list<string>
     */
    private function tokenize(string $text): array
    {
        // One word, or the hyphen leaves a bare "step" that any step counter also has.
        $text = preg_replace('/\bmulti[- ]step\b/u', 'multistep', mb_strtolower($text)) ?? '';
        $parts = preg_split('/[^\p{L}\p{N}]+/u', $text) ?: [];
        $tokens = [];
        foreach ($parts as $part) {
            if ($part === '') {
                continue;
            }
            $tokens[] = $part;
            // "lists invoices" must reach the same recipes as "list invoice".
            if (strlen($part) > 3 && str_ends_with($part, 's') && !preg_match('/(ss|us|is)$/', $part) && !in_array($part, self::NOT_PLURALS, true)) {
                $tokens[] = substr($part, 0, -1);
            }
            foreach (self::UKRAINIAN_WORDS[$part] ?? [] as $english) {
                $tokens[] = $english;
            }
            foreach (self::UKRAINIAN_STEMS as $stem => $english) {
                if (str_starts_with($part, $stem)) {
                    array_push($tokens, ...$english);
                }
            }
        }
        return array_values(array_unique($tokens));
    }

    /**
     * @param list<string> $tokens
     * @return list<string>
     */
    private function bigrams(array $tokens): array
    {
        $bigrams = [];
        for ($i = 0, $n = count($tokens) - 1; $i < $n; $i++) {
            $bigrams[] = $tokens[$i] . ' ' . $tokens[$i + 1];
        }
        return $bigrams;
    }

    /**
     * Cheap heuristic: pull a StudlyCase token out of the description, treat it
     * as a likely module name. Returns null when nothing matches the shape.
     */
    private function guessModule(string $description): ?string
    {
        if (preg_match_all('/\b([A-Z][a-z][a-zA-Z0-9]*)\b/', $description, $matches) === false) {
            return null;
        }
        $candidates = $matches[1] ?? [];
        return $candidates[0] ?? null;
    }
}
