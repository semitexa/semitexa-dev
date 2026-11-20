<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Intake;

/**
 * One error the running project logged, however many times: what the backlog
 * files as an idea. Its task id is its fingerprint, so the same signal is the
 * same task on every run and nothing else has to remember it.
 */
final readonly class RuntimeSignal
{
    public const ID_PREFIX = 'tk-rt-';

    /** How a next step the intake wrote begins. */
    public const NEXT_STEP_PREFIX = 'Runtime signal, not confirmed yet:';

    /** The whole next step the intake writes: prefix, count, "time(s)", log, first seen, last seen, last seen, grep, id, id. */
    private const NEXT_STEP = '%s seen %s %s in %s, from %s to %s. Read it: bin/semitexa logs:app --around=%s%s. A defect: approve it (bin/semitexa ai:work:approve --id=%s --words="<the owner\'s words>"); the environment or a test: bin/semitexa ai:work update --id=%s --status=discarded.';

    /**
     * @param string $message the logger's message, normalised
     * @param list<string> $routes the routes it was seen on, sorted
     */
    public function __construct(
        public string $fingerprint,
        public string $channel,
        public string $message,
        public ?string $exception,
        public ?string $site,
        public array $routes,
        public int $count,
        public string $firstSeen,
        public string $lastSeen,
    ) {}

    public function taskId(): string
    {
        return self::ID_PREFIX . substr($this->fingerprint, 0, 12);
    }

    /** Made of the class, the file, the channel and the route: never the exception's message or the request. */
    public function title(): string
    {
        $what = $this->exception !== null ? self::shortClass($this->exception) : self::cut($this->message, 100);
        $where = match (true) {
            $this->site !== null => ' in ' . $this->site,
            $this->exception !== null && $this->message !== '' => ': ' . self::cut($this->message, 80),
            default => '',
        };
        $around = array_values(array_filter([
            $this->channel !== '' ? $this->channel : null,
            match (count($this->routes)) {
                0 => null,
                1 => 'route ' . $this->routes[0],
                default => count($this->routes) . ' routes',
            },
        ]));

        return 'Runtime error: ' . ($what !== '' ? $what : 'an error with no message') . $where . ($around !== [] ? ' (' . implode(', ', $around) . ')' : '');
    }

    /** @return list<string> */
    public function contextRefs(): array
    {
        return match (true) {
            $this->site !== null => [$this->site],
            $this->exception !== null => [$this->exception],
            default => [],
        };
    }

    /** @param string $log the log it was read from, relative to the project */
    public function nextStep(string $log): string
    {
        $grep = $this->exception !== null ? ' --grep=' . self::shortClass($this->exception) : '';

        return sprintf(
            self::NEXT_STEP,
            self::NEXT_STEP_PREFIX,
            (string) $this->count,
            $this->count === 1 ? 'time' : 'times',
            $log,
            $this->firstSeen,
            $this->lastSeen,
            $this->lastSeen,
            $grep,
            $this->taskId(),
            $this->taskId(),
        );
    }

    /**
     * Whether a task's next step is the intake's own from its first byte to
     * its last: only the count, the log and the times may differ. A word
     * added before it, after it or inside it makes it someone's note.
     */
    public static function wroteNextStep(string $taskId, ?string $nextStep): bool
    {
        $id = preg_quote($taskId, '~');
        // A time is the log's own text: empty when the entry had none, and some loggers put a space in it.
        $time = '\S*(?: \S+)?';
        $pattern = sprintf(
            preg_quote(self::NEXT_STEP, '~'),
            preg_quote(self::NEXT_STEP_PREFIX, '~'),
            '\d+',
            'times?',
            '\S+',
            $time,
            $time,
            $time,
            '(?: --grep=\S+)?',
            $id,
            $id,
        );

        return $nextStep !== null && preg_match('~\A' . $pattern . '\z~', $nextStep) === 1;
    }

    private static function shortClass(string $class): string
    {
        $at = strrpos($class, '\\');

        return $at === false ? $class : substr($class, $at + 1);
    }

    private static function cut(string $text, int $length): string
    {
        return mb_strlen($text) > $length ? rtrim(mb_substr($text, 0, $length - 1)) . '…' : $text;
    }
}
