<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\State;

/**
 * Someone looked at a phase on a date. With no ticket open that is the only
 * thing separating "clean" from "nobody looked", so it says who looked and at
 * what, and goes stale: code moves on, and a month-old look proves little.
 */
final readonly class AuditRecord
{
    public const FRESH_DAYS = 30;

    /** Work is measured by what was asked for: it has no clean to look for. */
    public const PHASES = [Phase::RIGHT, Phase::FAST];

    public function __construct(
        public Phase $phase,
        public string $at,
        public string $by,
        public string $covered,
        public bool $withFramework,
    ) {}

    public function ageDays(\DateTimeImmutable $now): int
    {
        return intdiv(max(0, $now->getTimestamp() - $this->timestamp()), 86400);
    }

    public function isFresh(\DateTimeImmutable $now): bool
    {
        return $this->ageDays($now) < self::FRESH_DAYS;
    }

    /** createFromFormat rolls 30 February over to March, and only warns. */
    private static function isDate(string $at): bool
    {
        $date = \DateTimeImmutable::createFromFormat(DATE_ATOM, $at);
        $errors = \DateTimeImmutable::getLastErrors();

        return $date !== false && ($errors === false || ($errors['warning_count'] === 0 && $errors['error_count'] === 0));
    }

    /** A date to come is a clock or a hand-edit, not a look that happened. */
    public function isInTheFuture(\DateTimeImmutable $now): bool
    {
        return $this->timestamp() > $now->getTimestamp();
    }

    public function timestamp(): int
    {
        return (new \DateTimeImmutable($this->at))->getTimestamp();
    }

    /** @return array{phase: string, at: string, by: string, covered: string, with_framework: bool} */
    public function toArray(): array
    {
        return ['phase' => $this->phase->value, 'at' => $this->at, 'by' => $this->by, 'covered' => $this->covered, 'with_framework' => $this->withFramework];
    }

    /** A line short of any of these, or with a date that is no date, says nothing about a phase. */
    public static function fromStored(mixed $row): ?self
    {
        if (!is_array($row) || !is_string($row['phase'] ?? null) || !is_string($row['at'] ?? null) || !is_string($row['by'] ?? null) || !is_string($row['covered'] ?? null)) {
            return null;
        }
        $phase = Phase::tryFrom($row['phase']);
        if (!in_array($phase, self::PHASES, true) || trim($row['by']) === '' || trim($row['covered']) === '' || !self::isDate($row['at'])) {
            return null;
        }

        return new self($phase, $row['at'], $row['by'], $row['covered'], ($row['with_framework'] ?? false) === true);
    }
}
