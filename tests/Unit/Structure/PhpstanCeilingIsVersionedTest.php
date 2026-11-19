<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The release's phpstan ceiling is a versioned number, not a default an
 * environment variable can replace.
 *
 * It was `${PHPSTAN_CEILING:-179}` in the release script: one machine's shell
 * could raise the bar every release is held to without a commit anyone saw.
 */
final class PhpstanCeilingIsVersionedTest extends TestCase
{
    /** Why this guard exists; ai:verify prints it when the guard fails (PhpunitFailureHeadline). */
    public const RATIONALE = 'Why: a ceiling an environment variable can replace lets one machine\'s shell raise the bar every release is held to, without a commit anyone sees. Learned 2026-09-24: the release script read ${PHPSTAN_CEILING:-179}.';

    private const CEILING = __DIR__ . '/../../../resources/phpstan/phpstan-ceiling.json';
    private const GATE = __DIR__ . '/../../../resources/skills/release-readiness/scripts/release-auto-checks.sh';

    #[Test]
    public function the_ceiling_file_holds_a_number_and_the_analyser_it_was_measured_with(): void
    {
        $json = file_get_contents(self::CEILING);
        self::assertIsString($json, 'phpstan-ceiling.json must be readable');

        self::assertSame([], self::problems($json));
    }

    /**
     * What the gate reads (an integer `ceiling`, a string `analyser`) and what
     * a reviewer reads (why each raise was made). RatchetRecordTargets plans
     * this test when the record changes, so it is the check that reads it.
     *
     * @param array<string, mixed> $change keys to set in a well-formed record; null removes the key
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('malformed')]
    public function a_malformed_ceiling_is_named(array $change, array $expected): void
    {
        $record = ['ceiling' => 0, 'analyser' => '2.1.40', 'deliberate' => ['2026-10-29' => '12 -> 71 for one named cut.']];
        foreach ($change as $key => $value) {
            if ($value === null) {
                unset($record[$key]);
            } else {
                $record[$key] = $value;
            }
        }

        self::assertSame($expected, self::problems((string) json_encode($record)));
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function malformed(): array
    {
        return [
            'nothing wrong' => [[], []],
            'a ceiling in quotes' => [['ceiling' => '0'], ['ceiling must be an integer, 0 or more']],
            'a negative ceiling' => [['ceiling' => -1], ['ceiling must be an integer, 0 or more']],
            'no ceiling' => [['ceiling' => null], ['ceiling must be an integer, 0 or more']],
            'an analyser range' => [['analyser' => '^2.1'], ['analyser must be an exact version, like 2.1.40']],
            'an analyser as a number' => [['analyser' => 2.1], ['analyser must be an exact version, like 2.1.40']],
            'no analyser' => [['analyser' => null], ['analyser must be an exact version, like 2.1.40']],
            'no deliberate' => [['deliberate' => null], ['deliberate must be an object of date => reason: every raise needs somewhere to say why']],
            'a raise with no date' => [['deliberate' => ['last week' => 'raised']], ['deliberate "last week" must be keyed by its date, YYYY-MM-DD']],
            'a raise with no reason' => [['deliberate' => ['2026-10-29' => '']], ['deliberate "2026-10-29" must say why, in words']],
            'a raise with a number for a reason' => [['deliberate' => ['2026-10-29' => 71]], ['deliberate "2026-10-29" must say why, in words']],
        ];
    }

    #[Test]
    public function text_that_is_not_json_is_named(): void
    {
        self::assertSame(['not valid JSON: Syntax error'], self::problems('{"ceiling": 0,'));
        self::assertSame(['not valid JSON: Syntax error'], self::problems(''));
        self::assertSame(['not a JSON object'], self::problems('0'));
    }

    /**
     * @return list<string> what is wrong with the record, in the order it is read
     */
    private static function problems(string $json): array
    {
        try {
            $data = json_decode($json, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            return ['not valid JSON: ' . $e->getMessage()];
        }
        if (!is_array($data) || array_is_list($data)) {
            return ['not a JSON object'];
        }

        $problems = [];
        $ceiling = $data['ceiling'] ?? null;
        if (!is_int($ceiling) || $ceiling < 0) {
            $problems[] = 'ceiling must be an integer, 0 or more';
        }
        $analyser = $data['analyser'] ?? null;
        if (!is_string($analyser) || preg_match('/^\d+\.\d+\.\d+$/', $analyser) !== 1) {
            $problems[] = 'analyser must be an exact version, like 2.1.40';
        }
        $deliberate = $data['deliberate'] ?? null;
        if (!is_array($deliberate)) {
            return [...$problems, 'deliberate must be an object of date => reason: every raise needs somewhere to say why'];
        }
        foreach ($deliberate as $date => $reason) {
            $date = (string) $date;
            $parsed = \DateTimeImmutable::createFromFormat('!Y-m-d', $date);
            if ($parsed === false || $parsed->format('Y-m-d') !== $date) {
                $problems[] = "deliberate \"{$date}\" must be keyed by its date, YYYY-MM-DD";
            }
            if (!is_string($reason) || trim($reason) === '') {
                $problems[] = "deliberate \"{$date}\" must say why, in words";
            }
        }

        return $problems;
    }

    #[Test]
    public function the_release_gate_takes_no_override_from_the_environment(): void
    {
        $gate = (string) file_get_contents(self::GATE);
        // Positive first: the negative assertion below would pass on an empty read.
        self::assertStringContainsString('phpstan_neutrality_gate()', $gate);

        // A DEFAULT is the override: `${VAR:-179}` means "179 unless the shell
        // says otherwise". An empty `${VAR:-}` is only how the gate detects and
        // refuses someone still setting it.
        self::assertDoesNotMatchRegularExpression('/\$\{PHPSTAN_(CEILING|EXPECTED_ANALYSER):-[^}]/', $gate);
        self::assertStringContainsString('are no longer read from the environment', $gate);
        self::assertStringContainsString('resources/phpstan/phpstan-ceiling.json', $gate);
    }
}
