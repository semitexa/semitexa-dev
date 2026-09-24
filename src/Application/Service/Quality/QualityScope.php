<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * Which rises a verification is answerable for.
 *
 * Several agents share one tree. ai:verify passes the repos a change touched
 * (SEMITEXA_QUALITY_SCOPE); a rise in a key that belongs to another repo is
 * another agent's uncommitted work and must not fail this run. With no scope —
 * the full suite, the release — every rise counts.
 */
final class QualityScope
{
    /**
     * @param list<string>|null $repos packages/<name> or src/modules/<Name>; null = everything
     */
    public function __construct(private readonly ?array $repos)
    {
    }

    public static function fromEnv(): self
    {
        $raw = getenv('SEMITEXA_QUALITY_SCOPE');

        return new self(is_string($raw) && $raw !== '' ? array_values(array_filter(explode(',', $raw))) : null);
    }

    /**
     * The keys of $verdict that rose and are this scope's to answer for.
     *
     * @return array<string, array{from: int, to: int}>
     */
    public function rises(Verdict $verdict): array
    {
        return array_filter(
            $verdict->moved,
            fn (array $m, string $key): bool => $m['to'] > $m['from'] && $this->owns($key),
            ARRAY_FILTER_USE_BOTH,
        );
    }

    /**
     * A key names a package (`semitexa-orm`) or a module (`modules/Playground`).
     * A key that maps to no repo — a route, the unmeasured count — belongs to
     * no narrowed scope.
     */
    public function owns(string $key): bool
    {
        if ($this->repos === null) {
            return true;
        }

        return in_array(str_starts_with($key, 'modules/') ? 'src/' . $key : 'packages/' . $key, $this->repos, true);
    }
}
