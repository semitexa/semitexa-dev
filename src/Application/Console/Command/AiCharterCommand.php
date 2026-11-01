<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Console\Command;

use Semitexa\Core\Attribute\AsCommand;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Console\BaseCommand;
use Semitexa\Dev\Application\Service\Ai\Charter\Charter;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterInterview;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterQuestion;
use Semitexa\Dev\Application\Service\Ai\Charter\CharterStore;
use Semitexa\Dev\Application\Service\Ai\Charter\SuccessSign;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The project's charter, and the interview that fills it (ep-project-charter).
 *
 *   bin/semitexa ai:charter next                       the next question for the owner
 *   bin/semitexa ai:charter answer --field=goal --value="..."
 *   bin/semitexa ai:charter show                       the charter and what is still open
 *   bin/semitexa ai:charter check                      exit 1 while a question is unanswered
 *
 * Semitexa decides what to ask; the agent between it and the owner asks, in
 * the owner's words, and records the answer here.
 */
#[AsCommand(name: 'ai:charter', description: 'The project charter (goal, audience, problem, signs of success, constraints, non-goals, what the owner decides) and the interview that fills it: next | answer | show | check')]
final class AiCharterCommand extends BaseCommand
{
    private const ARTIFACT = 'semitexa.ai-charter/v1';
    private const TEXT_FIELDS = ['goal', 'audience', 'problem'];

    #[InjectAsReadonly]
    protected CharterStore $charterStore;

    public function __construct()
    {
        parent::__construct('ai:charter');
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'next | answer | show | check')
            ->addOption('field', null, InputOption::VALUE_REQUIRED, 'answer: ' . implode(' | ', CharterInterview::fields()))
            ->addOption('value', null, InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY, 'answer: the answer; repeat for a list field (signs, constraints, non_goals, owner_decides)')
            ->addOption('source', null, InputOption::VALUE_REQUIRED, 'answer --field=signs: the metric or check that measures this one sign (it is then computed)')
            ->addOption('none', null, InputOption::VALUE_NONE, 'answer: a list field\'s answer is "none"')
            ->addOption('keep', null, InputOption::VALUE_NONE, 'answer --field=owner_decides: keep the default list')
            ->addOption('replace', null, InputOption::VALUE_NONE, 'answer: the values replace a list field instead of adding to it (owner_decides: to the defaults)')
            ->addOption('json', null, InputOption::VALUE_NONE, 'One JSON envelope (the default when not interactive)');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $action = is_string($action) ? $action : '';
        $human = $input->isInteractive() && !(bool) $input->getOption('json');
        try {
            $charter = $this->charterStore->get();
        } catch (\RuntimeException $e) {
            return $this->fail($output, $e->getMessage());
        }

        return match ($action) {
            'next' => $this->next($output, $charter, $human),
            'show' => $this->emit($output, $this->state($charter) + ['charter' => $charter->toArray()]),
            'check' => $this->check($output, $charter),
            'answer' => $this->answer($input, $output, $charter),
            default => $this->fail($output, "unknown action \"{$action}\" (expected next | answer | show | check)"),
        };
    }

    private function next(OutputInterface $output, Charter $charter, bool $human): int
    {
        $state = $this->state($charter);
        if ($human) {
            $question = CharterInterview::next($charter);
            $output->writeln($question === null
                ? 'The charter is complete: nothing to ask the owner.'
                : sprintf("Ask the owner (%d open):\n\n  %s\n\nWhy: %s\nRecord: bin/semitexa %s", count(CharterInterview::open($charter)), $question->question, $question->why, $question->record));

            return self::SUCCESS;
        }

        return $this->emit($output, $state);
    }

    private function check(OutputInterface $output, Charter $charter): int
    {
        $open = CharterInterview::open($charter);
        $this->emit($output, $this->state($charter) + [
            'problems' => array_map(static fn (CharterQuestion $q): string => "unanswered: {$q->field}", $open),
        ]);

        return $open === [] ? self::SUCCESS : self::FAILURE;
    }

    private function answer(InputInterface $input, OutputInterface $output, Charter $charter): int
    {
        $field = $input->getOption('field');
        $field = is_string($field) ? $field : '';
        if (!in_array($field, CharterInterview::fields(), true)) {
            return $this->fail($output, '--field is one of: ' . implode(', ', CharterInterview::fields()));
        }
        $values = [];
        foreach ((array) $input->getOption('value') as $value) {
            if (is_string($value) && trim($value) !== '') {
                $values[] = trim($value);
            }
        }
        $source = $input->getOption('source');
        $source = is_string($source) && trim($source) !== '' ? trim($source) : null;
        $none = (bool) $input->getOption('none');
        $keep = (bool) $input->getOption('keep');
        $replace = (bool) $input->getOption('replace');

        if ($source !== null && $field !== 'signs') {
            return $this->fail($output, '--source belongs to a sign: --field=signs');
        }
        if ($keep && $field !== 'owner_decides') {
            return $this->fail($output, '--keep belongs to --field=owner_decides');
        }

        if (in_array($field, self::TEXT_FIELDS, true)) {
            if ($none || $keep || $replace || count($values) !== 1) {
                return $this->fail($output, "--field={$field} takes exactly one --value");
            }
            $updated = match ($field) {
                'goal' => $charter->with(goal: $values[0]),
                'audience' => $charter->with(audience: $values[0]),
                default => $charter->with(problem: $values[0]),
            };
        } elseif ($field === 'signs') {
            if ($none || $replace) {
                return $this->fail($output, 'a project needs at least one sign of success: --field=signs takes --value');
            }
            if ($values === []) {
                return $this->fail($output, '--field=signs takes one --value per sign');
            }
            if ($source !== null && count($values) !== 1) {
                return $this->fail($output, '--source measures one sign: give it with a single --value');
            }
            $signs = $charter->signs;
            $known = array_map(static fn (SuccessSign $s): string => mb_strtolower($s->text), $signs);
            foreach ($values as $text) {
                // The same answer recorded twice (a retry, two agents) is one sign.
                if (in_array(mb_strtolower($text), $known, true)) {
                    continue;
                }
                $known[] = mb_strtolower($text);
                $signs[] = new SuccessSign('s' . (count($signs) + 1), $text, $source);
            }
            $updated = $charter->with(signs: $signs);
        } else {
            $chosen = (int) $none + (int) $keep + (int) ($values !== []);
            if ($chosen !== 1) {
                return $this->fail($output, "--field={$field} takes --value (repeatable), or --none" . ($field === 'owner_decides' ? ', or --keep' : '') . ', one of them');
            }
            // Not asked yet, what the owner decides starts from the defaults: a
            // value adds to them, so "spending money" is never dropped by accident.
            $current = match ($field) {
                'constraints' => $charter->constraints,
                'non_goals' => $charter->nonGoals,
                default => $charter->ownerDecides ?? Charter::DEFAULT_OWNER_DECIDES,
            } ?? [];
            $list = match (true) {
                $none => [],
                $keep => Charter::DEFAULT_OWNER_DECIDES,
                $replace => array_values(array_unique($values)),
                default => array_values(array_unique([...$current, ...$values])),
            };
            $updated = match ($field) {
                'constraints' => $charter->with(constraints: $list),
                'non_goals' => $charter->with(nonGoals: $list),
                default => $charter->with(ownerDecides: $list),
            };
        }

        $updated = $updated->with(updatedAt: gmdate('c'));
        try {
            $this->charterStore->save($updated);
        } catch (\RuntimeException $e) {
            return $this->fail($output, 'the answer was not recorded: ' . $e->getMessage());
        }

        return $this->emit($output, ['status' => 'ok', 'recorded' => $field] + $this->state($updated) + ['charter' => $updated->toArray()]);
    }

    /** @return array<string, mixed> */
    private function state(Charter $charter): array
    {
        $next = CharterInterview::next($charter);

        return [
            'complete' => $next === null,
            'open' => count(CharterInterview::open($charter)),
            'next_question' => $next?->toArray(),
            'how_to_ask' => $next === null ? null : CharterInterview::HOW_TO_ASK,
        ];
    }

    /** @param array<string, mixed> $payload */
    private function emit(OutputInterface $output, array $payload): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT] + $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    private function fail(OutputInterface $output, string $message): int
    {
        $output->writeln(json_encode(['artifact' => self::ARTIFACT, 'status' => 'error', 'error' => $message], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}', OutputInterface::OUTPUT_RAW);

        return self::FAILURE;
    }
}
