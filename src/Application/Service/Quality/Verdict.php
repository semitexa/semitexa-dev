<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

/**
 * How one metric compares with its recorded baseline.
 *
 * Two-way on purpose. WORSE fails because it is a regression. BETTER fails too,
 * until it is recorded: an improvement nobody locks in is headroom the next
 * change can spend without anyone deciding it should — that is how the phpstan
 * ceiling sat above the real count, printing "lower it", release after release.
 */
final readonly class Verdict
{
    public const SAME = 'same';
    public const BETTER = 'better';
    public const WORSE = 'worse';
    public const NEW = 'new';

    /**
     * @param array<string, array{from: int, to: int}> $moved breakdown keys that changed
     */
    public function __construct(
        public string $metric,
        public string $status,
        public int $from,
        public int $to,
        public array $moved,
    ) {
    }

    /**
     * @param array{total: int, breakdown: array<string, int>}|null $baseline
     */
    public static function of(string $metric, Measurement $now, ?array $baseline): self
    {
        if ($baseline === null) {
            return new self($metric, self::NEW, 0, $now->total(), []);
        }

        $moved = [];
        $rose = false;
        foreach (array_keys($now->breakdown + $baseline['breakdown']) as $key) {
            $was = $baseline['breakdown'][$key] ?? 0;
            $is = $now->breakdown[$key] ?? 0;
            if ($was !== $is) {
                $moved[(string) $key] = ['from' => $was, 'to' => $is];
                // Per key, not only the total: a new problem in one package must
                // not hide behind a fix in another.
                $rose = $rose || $is > $was;
            }
        }
        ksort($moved);

        $status = match (true) {
            $rose || $now->total() > $baseline['total'] => self::WORSE,
            $moved !== [] || $now->total() < $baseline['total'] => self::BETTER,
            default => self::SAME,
        };

        return new self($metric, $status, $baseline['total'], $now->total(), $moved);
    }

    public function passes(): bool
    {
        return $this->status === self::SAME;
    }
}
