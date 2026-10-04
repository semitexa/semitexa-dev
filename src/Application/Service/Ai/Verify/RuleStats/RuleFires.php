<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\RuleStats;

/**
 * What one ai:verify run says about its rules: which families had a chance to
 * fire (their target ran to a verdict) and which rules did fire.
 *
 * Read from the verify-report envelope, the same artifact a trace keeps, so a
 * run recorded today and a run kept in a trace since April are read the same
 * way. A target that was skipped or incomplete gave nothing a chance.
 *
 * Rule ids: a PHPStan identifier (`semitexa.disallowErrorLog`), a
 * module_structure or live_tenancy code, a lint or docs command
 * (`lint:di`, `docs:lint`), or a ratchet test class (`StructuralOutlierBudgetTest`).
 */
final class RuleFires
{
    public const PHPSTAN = 'phpstan';
    public const MODULE_STRUCTURE = 'module_structure';
    public const LIVE_TENANCY = 'live_tenancy';
    public const RATCHETS = 'ratchets';

    private const RATCHET_TARGET = 'phpunit:packages/semitexa-dev/tests/Unit/Structure';

    /**
     * @param array<string, mixed> $envelope
     * @return array{at: string, chances: list<string>, fired: list<string>}|null null: not a verify report
     */
    public static function fromEnvelope(array $envelope): ?array
    {
        $at = $envelope['generated_at'] ?? null;
        $results = $envelope['results'] ?? null;
        if (!is_string($at) || !is_array($results)) {
            return null;
        }

        $chances = [];
        $fired = [];
        foreach ($results as $result) {
            if (!is_array($result) || !in_array($result['status'] ?? null, ['pass', 'fail'], true)) {
                continue;
            }
            $family = self::familyOf(self::text($result['type'] ?? null), self::text($result['id'] ?? null));
            if ($family === null) {
                continue;
            }
            $chances[] = $family;
            // An accepted hit is a firing too: the rule works, and this case was decided.
            foreach ((array) ($result['accepted'] ?? []) as $hit) {
                if (is_array($hit) && is_string($hit['identifier'] ?? $hit['rule'] ?? null)) {
                    $fired[] = self::text($hit['identifier'] ?? $hit['rule']);
                }
            }
            if ($result['status'] !== 'fail') {
                continue;
            }
            // A failed command gate or ratchet is a firing in its own right:
            // runs recorded before 2026-10-03 carry no violation for it.
            if ($family === self::RATCHETS) {
                $class = self::ratchetOf(self::text($result['signal'] ?? null));
                if ($class !== null) {
                    $fired[] = $class;
                }
            } elseif (str_contains($family, ':')) {
                $fired[] = $family;
            }
        }

        foreach ((array) ($envelope['violations'] ?? []) as $violation) {
            if (!is_array($violation)) {
                continue;
            }
            $check = self::text($violation['check'] ?? null);
            $rule = $violation['identifier'] ?? $violation['code'] ?? $violation['rule'] ?? null;
            // Lint and docs violations name the command, already counted above.
            if (is_string($rule) && $rule !== '' && $check !== 'lint' && $check !== 'docs') {
                $fired[] = $rule;
            }
        }

        return ['at' => $at, 'chances' => array_values(array_unique($chances)), 'fired' => array_values(array_unique($fired))];
    }

    /** The family a target belongs to: every rule of the family had a chance when it ran. */
    public static function familyOf(string $type, string $id): ?string
    {
        return match (true) {
            $type === 'phpstan_di' => self::PHPSTAN,
            $type === 'module_structure' => self::MODULE_STRUCTURE,
            $type === 'live_tenancy' => self::LIVE_TENANCY,
            $type === 'phpunit' && $id === self::RATCHET_TARGET => self::RATCHETS,
            // Planner lints are listed as `lint:lint:di`, guard lints as `lint:var-artifacts`.
            $type === 'lint' => str_starts_with($id, 'lint:lint:') ? substr($id, 5) : $id,
            $type === 'docs' => $id,
            default => null,
        };
    }

    /** The test class named by a failure headline: `StructuralOutlierBudgetTest::method — ...`. */
    private static function ratchetOf(string $signal): ?string
    {
        return preg_match('/^([A-Z][A-Za-z0-9_]*Test)::/', $signal, $match) === 1 ? $match[1] : null;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }
}
