<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Api;

/**
 * Answers "what does the framework offer for this" from the installed code.
 *
 * Nothing is indexed: scanning every framework file for a term takes about
 * 50 ms, and only the files that contain it are loaded. Reading the code each
 * time is also why the answer cannot drift from what is installed.
 */
final class ApiCatalog
{
    public const int SEARCH_LIMIT = 20;

    private const string FRAMEWORK_PREFIX = 'Semitexa\\';

    /** More than this on a parameter's line is a list to ask for, not to print. */
    private const int MAX_PARAMETER_IMPLEMENTERS = 8;

    /** A trait or an attribute exists to be used by application code. */
    private const int USABLE_KIND_BONUS = 45;

    /** The class of exactly that name beats anything merely containing the word. */
    private const int EXACT_CLASS_BONUS = 60;

    /**
     * @param array<string, string> $classes class name => file
     */
    public function __construct(
        private readonly array $classes,
        private readonly ApiReflector $reflector,
    ) {}

    /**
     * @param array<string, string> $classMap class name => file
     */
    public static function forFramework(array $classMap, string $projectRoot): self
    {
        $applicationCode = rtrim($projectRoot, '/') . '/src/';
        $classes = [];
        foreach ($classMap as $class => $file) {
            if (
                str_starts_with($class, self::FRAMEWORK_PREFIX)
                && !str_contains($class, '\\Tests\\')
                && !str_contains($file, '/tests/')
                && !str_starts_with($file, $applicationCode)
            ) {
                $classes[$class] = $file;
            }
        }

        return new self($classes, new ApiReflector($projectRoot));
    }

    /**
     * Every class the name could mean: the exact one for a full name, otherwise
     * all with that short name, in no particular favour.
     *
     * @return list<string>
     */
    public function resolve(string $name): array
    {
        $wanted = strtolower(ltrim(trim($name), '\\'));
        if ($wanted === '') {
            return [];
        }

        $matches = [];
        foreach (array_keys($this->classes) as $class) {
            if (strtolower($class) === $wanted) {
                return [$class];
            }
            if (strtolower($this->shortNameOf($class)) === $wanted) {
                $matches[] = $class;
            }
        }
        sort($matches);

        return $matches;
    }

    /**
     * The only class the name means, or the only one under the stability
     * contract. Two equal candidates give null rather than a guess: a confident
     * answer about the wrong class costs more than one more call.
     */
    public function lookup(string $name): ?ApiClass
    {
        $described = [];
        foreach ($this->resolve($name) as $class) {
            $class = $this->describe($class);
            if ($class !== null) {
                $described[] = $class;
            }
        }

        if (count($described) > 1) {
            $described = array_values(array_filter($described, static fn (ApiClass $class): bool => $class->stable));
        }

        return count($described) === 1 ? $this->withImplementers($described[0]) : null;
    }

    /**
     * Classes whose short name contains the name, the closest first.
     *
     * @return list<string>
     */
    public function similar(string $name, int $limit = 5): array
    {
        $wanted = strtolower(trim($name));
        $similar = array_values(array_filter(
            array_keys($this->classes),
            fn (string $class): bool => $wanted !== '' && str_contains(strtolower($this->shortNameOf($class)), $wanted),
        ));
        usort($similar, fn (string $a, string $b): int => [strlen($this->shortNameOf($a)), $a] <=> [strlen($this->shortNameOf($b)), $b]);

        return array_slice($similar, 0, $limit);
    }

    public function describe(string $class): ?ApiClass
    {
        try {
            /** @var class-string $class */
            return $this->reflector->describe($class);
        } catch (\Throwable) {
            // A class whose optional dependency is not installed cannot load.
            return null;
        }
    }

    private function withImplementers(ApiClass $class): ApiClass
    {
        $parameters = [];
        foreach ($class->parameters as $parameter) {
            $type = ltrim($parameter->type, '?');
            $implementers = str_starts_with($type, self::FRAMEWORK_PREFIX) && interface_exists($type)
                ? $this->implementersOf($type)
                : [];
            $parameters[] = count($implementers) <= self::MAX_PARAMETER_IMPLEMENTERS
                ? $parameter->withImplementers($implementers)
                : $parameter;
        }

        return $class->with($parameters, $class->kind === 'interface' ? $this->implementersOf($class->name) : []);
    }

    /**
     * What can actually be passed: every concrete class below the interface,
     * through subclasses and sub-interfaces. Found from the source so that only
     * the classes in question are loaded, a level at a time; a class implementing
     * it under a `use ... as` alias is the one thing this does not see.
     *
     * @return list<string>
     */
    private function implementersOf(string $interface): array
    {
        $descendants = [];
        $names = [$this->shortNameOf($interface)];
        do {
            $pattern = '/\b(?:implements|extends)\b[^{]*\b(?:'
                . implode('|', array_map(static fn (string $name): string => preg_quote($name, '/'), $names))
                . ')\b/';
            $found = [];
            foreach ($this->classes as $class => $file) {
                if (isset($descendants[$class])) {
                    continue;
                }
                $source = is_readable($file) ? file_get_contents($file) : false;
                if ($source !== false && preg_match($pattern, $source) === 1 && $this->isA($class, $interface)) {
                    $descendants[$class] = true;
                    $found[] = $this->shortNameOf($class);
                }
            }
            $names = array_values(array_unique($found));
        } while ($names !== []);

        $implementers = array_values(array_filter(array_keys($descendants), $this->isConcrete(...)));
        sort($implementers);

        return $implementers;
    }

    private function isConcrete(string $class): bool
    {
        /** @var class-string $class */
        $reflection = new \ReflectionClass($class);

        return !$reflection->isInterface() && !$reflection->isAbstract();
    }

    /** The text only suggests it: another interface can share the short name. */
    private function isA(string $class, string $interface): bool
    {
        try {
            return is_subclass_of($class, $interface);
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * Every word has to match. When nothing does, the best partial matches are
     * offered and marked as such: "max length validator" finds validateMaxLength
     * although nothing is spelled "validator", and silence would send the reader
     * back to grep.
     */
    public function search(string $term): ApiSearchResult
    {
        $tokens = $this->tokensOf($term);
        if ($tokens === []) {
            return new ApiSearchResult([], 0, []);
        }

        $result = $this->matching($tokens, count($tokens));
        if ($result->hits !== [] || count($tokens) === 1) {
            return $result;
        }

        return $this->matching($tokens, intdiv(count($tokens) + 1, 2), partial: true);
    }

    /**
     * @param list<string> $tokens
     */
    private function matching(array $tokens, int $required, bool $partial = false): ApiSearchResult
    {
        $hits = [];
        $unread = [];
        foreach ($this->classes as $class => $file) {
            if (!$this->fileMentions($file, $tokens, $required)) {
                continue;
            }
            $described = $this->describe($class);
            if ($described === null) {
                $unread[] = $class;
                continue;
            }
            array_push($hits, ...$this->hitsIn($described, $tokens, $required));
        }

        usort($hits, static fn (ApiHit $a, ApiHit $b): int => [$b->score, $a->class, $a->member] <=> [$a->score, $b->class, $b->member]);

        return new ApiSearchResult(array_slice($hits, 0, self::SEARCH_LIMIT), count($hits), $unread, $partial);
    }

    /**
     * Anything but letters and digits separates words, so `validateRegex()`,
     * `#[Column]` and a full class name can be pasted as they are.
     *
     * @return list<string>
     */
    private function tokensOf(string $term): array
    {
        $parts = preg_split('/[^a-z0-9]+/', strtolower($term)) ?: [];

        return array_values(array_filter($parts, static fn (string $part): bool => $part !== ''));
    }

    /**
     * @param list<string> $tokens
     */
    private function fileMentions(string $file, array $tokens, int $required): bool
    {
        $source = is_readable($file) ? file_get_contents($file) : false;
        if ($source === false) {
            return false;
        }

        $mentioned = 0;
        foreach ($tokens as $token) {
            if (stripos($source, $token) !== false) {
                $mentioned++;
            }
        }

        return $mentioned >= $required;
    }

    /**
     * @param list<string> $tokens
     *
     * @return list<ApiHit>
     */
    private function hitsIn(ApiClass $class, array $tokens, int $required): array
    {
        $hits = [];
        $owner = $class->name . ' ' . $class->kind;
        $bonus = ($class->stable ? 25 : 0) + (in_array($class->kind, ['trait', 'attribute'], true) ? self::USABLE_KIND_BONUS : 0);
        $exact = strtolower($class->shortName()) === implode('', $tokens) ? self::EXACT_CLASS_BONUS : 0;

        $score = $this->score($tokens, $required, $class->shortName(), $owner, $class->summary, $bonus + $exact);
        if ($score !== null) {
            $hits[] = $this->hit($score, $class, null, $class->kind . ' ' . $class->shortName(), $class->summary);
        }

        foreach ($class->parameters as $parameter) {
            $score = $this->score($tokens, $required, $parameter->name, $owner, $parameter->summary, $bonus);
            if ($score !== null) {
                $hits[] = $this->hit($score, $class, $parameter->name, $parameter->signature(), $parameter->summary);
            }
        }

        foreach ($class->members as $member) {
            if ($member->from !== null) {
                continue; // The declaring class answers for it, once.
            }
            $score = $this->score($tokens, $required, $member->name, $owner, $member->summary, $bonus);
            if ($score !== null) {
                $hits[] = $this->hit($score, $class, $member->name, $member->signature, $member->summary);
            }
        }

        return $hits;
    }

    private function hit(int $score, ApiClass $class, ?string $member, string $signature, string $summary): ApiHit
    {
        return new ApiHit($score, $class->name, $class->kind, $class->file, $class->stable, $member, $signature, $summary);
    }

    /**
     * Null when too few words match, or when only the owner matched: every member
     * of `Column` is not an answer to "column", the class is. The bonus rewards a
     * name, not a word that happens to sit in a doc comment.
     *
     * @param list<string> $tokens
     */
    private function score(array $tokens, int $required, string $identifier, string $owner, string $summary, int $bonus): ?int
    {
        $name = strtolower($identifier);
        $ownerName = strtolower($owner);
        $text = strtolower($summary);

        $inName = 0;
        $inOwner = 0;
        $inText = 0;
        foreach ($tokens as $token) {
            if (str_contains($name, $token)) {
                $inName++;
            } elseif (str_contains($ownerName, $token)) {
                $inOwner++;
            } elseif (str_contains($text, $token)) {
                $inText++;
            }
        }
        if ($inName + $inOwner + $inText < $required || $inName + $inText === 0) {
            return null;
        }

        return $inName * 30 + $inOwner * 20 + $inText * 5
            + ($name === implode('', $tokens) ? 40 : 0)
            + ($inName > 0 ? $bonus : 0);
    }

    private function shortNameOf(string $class): string
    {
        $separator = strrpos($class, '\\');

        return $separator === false ? $class : substr($class, $separator + 1);
    }
}
