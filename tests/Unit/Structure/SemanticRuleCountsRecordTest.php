<?php

declare(strict_types=1);

namespace Semitexa\Dev\Tests\Unit\Structure;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

/**
 * The semantic-rule snapshot the release gate compares against is well formed.
 *
 * The gate (semantic_rule_ratchet_gate in release-auto-checks.sh) reads
 * `packages` and `total` from it and takes whatever it finds: a package
 * misspelled there is a count the gate can never match, and a `total` nobody
 * re-added says one thing while the entries say another.
 *
 * It is also the check that READS this record. Without a test selected for
 * it, a commit changing only the record verified `incomplete` (no check read
 * it) and could not be pushed; RatchetRecordTargets plans this test for it.
 */
final class SemanticRuleCountsRecordTest extends TestCase
{
    /** Why this guard exists; ai:verify prints it when the guard fails (PhpunitFailureHeadline). */
    public const RATIONALE = 'Why: the release gate compares every package against this record and takes what it finds, so a misspelled package or a stale total is a bar nobody can meet or nobody set. Learned 2026-11-19: a commit lowering one count (semitexa-graphql 3 -> 1) was read by no check and could not be pushed.';

    private const RECORD = __DIR__ . '/../../../resources/phpstan/semantic-rule-counts.json';

    /** A record no rule below objects to; each malformed case changes one thing in it. */
    private const WELL_FORMED = [
        '_' => 'what this is',
        'measured_at' => '2026-11-19',
        'command' => 'vendor/bin/phpstan analyse',
        'total' => 3,
        'deliberate' => ['2026-11-19 semitexa-a 3 -> 1' => 'A real fix.'],
        'packages' => ['semitexa-a' => 1, 'semitexa-b2-c' => 2],
    ];

    #[Test]
    public function the_shipped_record_is_well_formed(): void
    {
        $json = file_get_contents(self::RECORD);
        self::assertIsString($json, 'semantic-rule-counts.json must be readable');

        // In the workspace the packages sit beside this one; installed under
        // vendor/semitexa they are named differently, and that part is skipped.
        $packagesDir = dirname(__DIR__, 4);
        $packagesDir = basename($packagesDir) === 'packages' ? $packagesDir : null;

        self::assertSame([], self::problems($json, $packagesDir));

        // The rules ran over something: an empty record would pass most of them.
        $data = json_decode($json, true);
        self::assertIsArray($data);
        self::assertIsArray($data['packages']);
        self::assertNotSame([], $data['packages'], 'a record with no package is not a snapshot of this tree');
        self::assertArrayHasKey('semitexa-dev', $data['packages'], 'this package holds two findings of its own');
    }

    /**
     * @param array<string, mixed> $change keys to set in the well-formed record; null removes the key
     * @param list<string> $expected
     */
    #[Test]
    #[DataProvider('malformed')]
    public function a_malformed_record_is_named(array $change, array $expected): void
    {
        $record = self::WELL_FORMED;
        foreach ($change as $key => $value) {
            if ($value === null) {
                unset($record[$key]);
            } else {
                $record[$key] = $value;
            }
        }

        self::assertSame($expected, self::problems((string) json_encode($record), null));
    }

    /**
     * @return array<string, array{array<string, mixed>, list<string>}>
     */
    public static function malformed(): array
    {
        return [
            'nothing wrong' => [[], []],
            'total is not the sum' => [['total' => 4], ['total is 4, the packages add up to 3']],
            'total is a string' => [['total' => '3'], ['total must be an integer']],
            'no total' => [['total' => null], ['total must be an integer']],
            'a package outside the naming' => [
                ['packages' => ['Semitexa-A' => 1, 'core' => 2]],
                ['package "Semitexa-A" is not named semitexa-<name>', 'package "core" is not named semitexa-<name>'],
            ],
            'a clean package listed' => [
                ['packages' => ['semitexa-a' => 3, 'semitexa-b' => 0]],
                ['package "semitexa-b" is listed with 0: a clean package is absent'],
            ],
            'a count that is not a number' => [
                ['packages' => ['semitexa-a' => '3']],
                ['package "semitexa-a" must hold a positive integer', 'total is 3, the packages add up to 0'],
            ],
            'a negative count' => [
                ['packages' => ['semitexa-a' => 4, 'semitexa-b' => -1]],
                ['package "semitexa-b" must hold a positive integer', 'total is 3, the packages add up to 4'],
            ],
            'packages is a list' => [['packages' => [1, 2]], ['package "0" is not named semitexa-<name>', 'package "1" is not named semitexa-<name>']],
            'no packages' => [['packages' => null], ['packages must be an object of package => count']],
            'an empty reason' => [
                ['deliberate' => ['2026-11-19 semitexa-a 3 -> 1' => '  ']],
                ['deliberate "2026-11-19 semitexa-a 3 -> 1" must say why, in words'],
            ],
            'a reason that is not text' => [
                ['deliberate' => ['2026-11-19 semitexa-a 3 -> 1' => ['why']]],
                ['deliberate "2026-11-19 semitexa-a 3 -> 1" must say why, in words'],
            ],
            'no deliberate' => [['deliberate' => null], ['deliberate must be an object of change => reason']],
            'a date in another form' => [['measured_at' => '19.11.2026'], ['measured_at must be a date, YYYY-MM-DD']],
            'a date that does not exist' => [['measured_at' => '2026-13-40'], ['measured_at must be a date, YYYY-MM-DD']],
            'no date' => [['measured_at' => null], ['measured_at must be a date, YYYY-MM-DD']],
        ];
    }

    #[Test]
    public function text_that_is_not_json_is_named(): void
    {
        self::assertSame(['not valid JSON: Syntax error'], self::problems('{"total": 3,', null));
        self::assertSame(['not a JSON object'], self::problems('3', null));
        self::assertSame(['not valid JSON: Syntax error'], self::problems('', null));
    }

    #[Test]
    public function a_package_that_is_not_in_the_workspace_is_named(): void
    {
        $packages = sys_get_temp_dir() . '/semantic-rule-counts-' . getmypid() . '/packages';
        self::assertTrue(mkdir($packages . '/semitexa-a', 0777, true));

        try {
            self::assertSame(
                ['package "semitexa-b2-c" names no directory under packages/'],
                self::problems((string) json_encode(self::WELL_FORMED), $packages),
            );
        } finally {
            rmdir($packages . '/semitexa-a');
            rmdir($packages);
            rmdir(dirname($packages));
        }
    }

    /**
     * @param ?string $packagesDir the workspace's packages/, or null where there is none to compare with
     * @return list<string> what is wrong with the record, in the order it is read
     */
    private static function problems(string $json, ?string $packagesDir): array
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
        $measuredAt = $data['measured_at'] ?? null;
        $date = is_string($measuredAt) ? \DateTimeImmutable::createFromFormat('!Y-m-d', $measuredAt) : false;
        if ($date === false || $date->format('Y-m-d') !== $measuredAt) {
            $problems[] = 'measured_at must be a date, YYYY-MM-DD';
        }

        $deliberate = $data['deliberate'] ?? null;
        if (!is_array($deliberate)) {
            $problems[] = 'deliberate must be an object of change => reason';
        } else {
            foreach ($deliberate as $change => $reason) {
                if (!is_string($reason) || trim($reason) === '') {
                    $problems[] = "deliberate \"{$change}\" must say why, in words";
                }
            }
        }

        $packages = $data['packages'] ?? null;
        $sum = null;
        if (!is_array($packages)) {
            $problems[] = 'packages must be an object of package => count';
        } else {
            $sum = 0;
            foreach ($packages as $package => $count) {
                $package = (string) $package;
                $named = preg_match('/^semitexa-[a-z0-9-]+$/', $package) === 1;
                if (!$named) {
                    $problems[] = "package \"{$package}\" is not named semitexa-<name>";
                }
                if ($count === 0) {
                    $problems[] = "package \"{$package}\" is listed with 0: a clean package is absent";
                    continue;
                }
                if (!is_int($count) || $count < 0) {
                    $problems[] = "package \"{$package}\" must hold a positive integer";
                    continue;
                }
                $sum += $count;
                if ($named && $packagesDir !== null && !is_dir($packagesDir . '/' . $package)) {
                    $problems[] = "package \"{$package}\" names no directory under packages/";
                }
            }
        }

        $total = $data['total'] ?? null;
        if (!is_int($total)) {
            $problems[] = 'total must be an integer';
        } elseif ($sum !== null && $total !== $sum) {
            $problems[] = "total is {$total}, the packages add up to {$sum}";
        }

        return $problems;
    }
}
