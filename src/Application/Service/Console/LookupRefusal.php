<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Console;

use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * A lookup that found nothing, answered in the format the caller asked for.
 *
 * `ai:ask route|module|event` printed a Symfony error block even under --json,
 * so an agent parsing stdout got nothing to parse and lost the one useful part
 * — which names DO exist. Under --json this is the command's own artifact with
 * `status: error`, the candidates, and where to look next.
 */
final class LookupRefusal
{
    /**
     * @param list<string>                                                 $candidates
     * @param list<array{cmd: string, args: list<string>, why: string}> $nextCommand
     */
    public static function refuse(
        InputInterface $input,
        OutputInterface $output,
        string $artifact,
        string $error,
        string $candidatesLabel = '',
        array $candidates = [],
        array $nextCommand = [],
    ): int {
        if ($input->hasOption('json') && $input->getOption('json')) {
            $output->writeln(json_encode([
                'artifact' => $artifact,
                'status' => 'error',
                'error' => $error,
                'candidates' => $candidates,
                'next_command' => $nextCommand,
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));

            return Command::FAILURE;
        }

        $io = new SymfonyStyle($input, $output);
        $io->error($error);
        if ($candidates !== []) {
            $io->text($candidatesLabel !== '' ? $candidatesLabel . ':' : '');
            $io->listing($candidates);
        }
        foreach ($nextCommand as $next) {
            $io->note(sprintf('%s %s — %s', $next['cmd'], implode(' ', $next['args']), $next['why']));
        }

        return Command::FAILURE;
    }

    /**
     * The names closest to what was asked for, best first.
     *
     * @param list<string> $names
     *
     * @return list<string>
     */
    public static function closest(string $requested, array $names, int $limit = 5): array
    {
        $needle = strtolower(trim($requested, '/'));
        $scored = [];
        foreach (array_unique($names) as $name) {
            $score = levenshtein(strtolower($requested), strtolower($name));
            // /login is far from /os/login by edit distance, but it is the
            // route the caller means; containing the whole request wins.
            if ($needle !== '' && str_contains(strtolower($name), $needle)) {
                $score -= 100;
            }
            $scored[$name] = $score;
        }
        asort($scored);

        return array_slice(array_keys($scored), 0, $limit);
    }
}
