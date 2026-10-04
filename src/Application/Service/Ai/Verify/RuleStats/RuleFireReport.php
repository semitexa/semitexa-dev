<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Verify\RuleStats;

/**
 * Per rule: how many runs gave it a chance, how many times it fired, when it
 * last did. A rule with many chances and no firing is not proved useless — a
 * guard on code that rarely changes (migrations, auth) is quiet by design —
 * but it is the list to read when deciding what the rule set still needs.
 */
final class RuleFireReport
{
    /**
     * @param array<string, string>                                              $catalog rule id => family
     * @param list<array{at: string, chances: list<string>, fired: list<string>}> $runs
     * @return array{runs: int, since: ?string, rules: list<array{rule: string, family: string, chances: int, fires: int, last_fired: ?string}>, retired: array<string, int>}
     */
    public static function build(array $catalog, array $runs): array
    {
        $chances = [];
        $fires = [];
        $last = [];
        foreach ($runs as $run) {
            foreach ($run['chances'] as $family) {
                $chances[$family] = ($chances[$family] ?? 0) + 1;
            }
            foreach ($run['fired'] as $rule) {
                $fires[$rule] = ($fires[$rule] ?? 0) + 1;
                $last[$rule] = $run['at'];
            }
        }

        $rules = [];
        foreach ($catalog as $rule => $family) {
            $rules[] = [
                'rule'       => $rule,
                'family'     => $family,
                'chances'    => $chances[$family] ?? 0,
                'fires'      => $fires[$rule] ?? 0,
                'last_fired' => $last[$rule] ?? null,
            ];
        }
        // Dormant first (most chances without a firing), then the rest by fires.
        usort($rules, static fn (array $a, array $b): int => [$a['fires'] > 0, $a['fires'] > 0 ? -$a['fires'] : -$a['chances'], $a['rule']]
            <=> [$b['fires'] > 0, $b['fires'] > 0 ? -$b['fires'] : -$b['chances'], $b['rule']]);

        // Fired in the past by a name the catalog no longer has: renamed or removed.
        $retired = array_diff_key($fires, $catalog);
        ksort($retired);

        return ['runs' => count($runs), 'since' => $runs[0]['at'] ?? null, 'rules' => $rules, 'retired' => $retired];
    }
}
