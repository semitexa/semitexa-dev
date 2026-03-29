<?php

declare(strict_types=1);

namespace Semitexa\Dev\Console\Command;

use Semitexa\Core\Attributes\AsCommand;
use Semitexa\Core\Console\Command\BaseCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

#[AsCommand(name: 'logs:app', description: 'Read application log files with filtering (optimised for LLM agents)')]
final class LogsAppCommand extends BaseCommand
{
    private const LOG_DIR = 'var/log';

    private const array LOG_FILES = [
        'app' => 'app.log',
        'debug' => 'debug.log',
        'session-debug' => 'session-debug.log',
        'swoole' => 'swoole.log',
    ];

    /** Monolog: [2026-03-29 08:25:00] channel.LEVEL: message {context} */
    private const MONOLOG_PATTERN = '/^\[(\d{4}-\d{2}-\d{2}[T ]\d{2}:\d{2}:\d{2}[^\]]*)\]\s+(\S+)\.(\w+):\s+(.*)/';

    /** JSON log line: {"timestamp":"...", ...} */
    private const JSON_LOG_PATTERN = '/^\{.*"timestamp"\s*:/';

    protected function configure(): void
    {
        $this
            ->addOption('file', null, InputOption::VALUE_REQUIRED, 'Log file: app, debug, session-debug, swoole', 'app')
            ->addOption('lines', null, InputOption::VALUE_REQUIRED, 'Number of lines from end', '100')
            ->addOption('grep', null, InputOption::VALUE_REQUIRED, 'Regex filter pattern')
            ->addOption('level', null, InputOption::VALUE_REQUIRED, 'Filter by log level (ERROR, WARNING, INFO, DEBUG)')
            ->addOption('since', null, InputOption::VALUE_REQUIRED, 'Show entries since datetime or relative (-1h, -30m)')
            ->addOption('around', null, InputOption::VALUE_REQUIRED, 'Show entries around a timestamp (±context lines)')
            ->addOption('context', null, InputOption::VALUE_REQUIRED, 'Lines around --around timestamp', '20')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Output as structured JSON')
            ->addOption('list', null, InputOption::VALUE_NONE, 'List available log files with sizes');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $root = $this->getProjectRoot();
        $logDir = $root . '/' . self::LOG_DIR;

        if ($input->getOption('list')) {
            return $this->listFiles($io, $input, $logDir);
        }

        $fileKey = $input->getOption('file');
        $filename = self::LOG_FILES[$fileKey] ?? null;

        if ($filename === null) {
            $io->error("Unknown log file: {$fileKey}. Available: " . implode(', ', array_keys(self::LOG_FILES)));
            return Command::FAILURE;
        }

        $filePath = $logDir . '/' . $filename;

        if (!is_file($filePath)) {
            $io->error("Log file not found: {$filePath}");
            return Command::FAILURE;
        }

        $lines = (int) $input->getOption('lines');
        $grep = $input->getOption('grep');
        $level = $input->getOption('level') ? strtoupper($input->getOption('level')) : null;
        $since = $input->getOption('since');
        $around = $input->getOption('around');
        $contextLines = (int) $input->getOption('context');
        $asJson = $input->getOption('json');

        if ($around !== null) {
            return $this->aroundMode($io, $input, $filePath, $filename, $around, $contextLines, $grep, $asJson);
        }

        $rawLines = $this->readTail($filePath, $lines, $grep, $level, $since);
        $entries = array_map(fn(string $line) => $this->parseLine($line), $rawLines);

        if ($asJson) {
            $entries = array_map(fn(array $e) => array_diff_key($e, ['raw' => 1]), $entries);
            $stat = stat($filePath);
            $output->writeln(json_encode([
                'command' => 'logs:app',
                'query' => [
                    'file' => $filename,
                    'lines' => $lines,
                    'grep' => $grep,
                    'level' => $level,
                    'since' => $since,
                ],
                'file' => [
                    'name' => $filename,
                    'size' => $this->formatSize($stat['size']),
                    'size_bytes' => $stat['size'],
                    'modified' => date('c', $stat['mtime']),
                ],
                'entries' => $entries,
                'total' => count($entries),
                'truncated' => false,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        if (empty($entries)) {
            $io->text('No matching log entries found.');
            return Command::SUCCESS;
        }

        foreach ($rawLines as $line) {
            $output->writeln($line);
        }

        return Command::SUCCESS;
    }

    private function listFiles(SymfonyStyle $io, InputInterface $input, string $logDir): int
    {
        $files = [];
        foreach (glob($logDir . '/*.log') as $path) {
            $name = basename($path);
            $stat = stat($path);
            $files[] = [
                'name' => $name,
                'alias' => array_search($name, self::LOG_FILES, true) ?: null,
                'size' => $this->formatSize($stat['size']),
                'size_bytes' => $stat['size'],
                'modified' => date('c', $stat['mtime']),
                'lines_estimate' => $this->estimateLines($path),
            ];
        }

        if ($input->getOption('json')) {
            $io->writeln(json_encode([
                'command' => 'logs:app --list',
                'log_dir' => $logDir,
                'files' => $files,
                'total' => count($files),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            return Command::SUCCESS;
        }

        $io->title('Available Log Files');
        $rows = [];
        foreach ($files as $f) {
            $alias = $f['alias'] ? " ({$f['alias']})" : '';
            $rows[] = [$f['name'] . $alias, $f['size'], $f['modified']];
        }
        $io->table(['File', 'Size', 'Modified'], $rows);
        return Command::SUCCESS;
    }

    /**
     * Read last N lines from file efficiently using SplFileObject (no full file load).
     *
     * @return string[]
     */
    private function readTail(string $filePath, int $maxLines, ?string $grep, ?string $level, ?string $since): array
    {
        $sinceTs = $since !== null ? $this->parseDateTime($since) : null;

        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        if ($totalLines === 0) {
            return [];
        }

        // We need to scan more lines than requested if filtering
        $hasFilter = $grep !== null || $level !== null || $sinceTs !== null;
        $scanLimit = $hasFilter ? min($totalLines, $maxLines * 20) : min($totalLines, $maxLines);

        $startLine = max(0, $totalLines - $scanLimit);
        $result = [];

        $file->seek($startLine);
        while (!$file->eof()) {
            $line = rtrim($file->current(), "\r\n");
            $file->next();

            if ($line === '') {
                continue;
            }

            if ($grep !== null && !@preg_match('/' . $grep . '/i', $line)) {
                continue;
            }

            if ($level !== null) {
                $parsed = $this->parseLine($line);
                if (($parsed['level'] ?? null) !== $level) {
                    continue;
                }
            }

            if ($sinceTs !== null) {
                $parsed = $parsed ?? $this->parseLine($line);
                if (isset($parsed['timestamp'])) {
                    $lineTs = strtotime($parsed['timestamp']);
                    if ($lineTs !== false && $lineTs < $sinceTs) {
                        $parsed = null;
                        continue;
                    }
                }
                $parsed = null;
            }

            $result[] = $line;

            if (count($result) > $maxLines) {
                array_shift($result);
            }
        }

        return $result;
    }

    private function aroundMode(
        SymfonyStyle $io,
        InputInterface $input,
        string $filePath,
        string $filename,
        string $around,
        int $contextLines,
        ?string $grep,
        bool $asJson,
    ): int {
        $targetTs = $this->parseDateTime($around);
        if ($targetTs === null) {
            $io->error("Cannot parse timestamp: {$around}");
            return Command::FAILURE;
        }

        $file = new \SplFileObject($filePath, 'r');
        $file->seek(PHP_INT_MAX);
        $totalLines = $file->key();

        // Binary search for the target timestamp
        $lo = 0;
        $hi = $totalLines - 1;
        $foundLine = 0;

        while ($lo <= $hi) {
            $mid = intdiv($lo + $hi, 2);
            $file->seek($mid);
            $line = rtrim($file->current(), "\r\n");
            $parsed = $this->parseLine($line);
            $lineTs = isset($parsed['timestamp']) ? strtotime($parsed['timestamp']) : null;

            if ($lineTs === null || $lineTs === false) {
                // Can't parse — move forward
                $lo = $mid + 1;
                continue;
            }

            if ($lineTs < $targetTs) {
                $lo = $mid + 1;
                $foundLine = $mid;
            } elseif ($lineTs > $targetTs) {
                $hi = $mid - 1;
            } else {
                $foundLine = $mid;
                break;
            }
        }

        $startLine = max(0, $foundLine - $contextLines);
        $endLine = min($totalLines - 1, $foundLine + $contextLines);

        $rawLines = [];
        $file->seek($startLine);
        for ($i = $startLine; $i <= $endLine && !$file->eof(); $i++) {
            $line = rtrim($file->current(), "\r\n");
            $file->next();

            if ($grep !== null && !@preg_match('/' . $grep . '/i', $line)) {
                continue;
            }

            $rawLines[] = $line;
        }

        $entries = array_map(fn(string $l) => $this->parseLine($l), $rawLines);

        if ($asJson) {
            $entries = array_map(fn(array $e) => array_diff_key($e, ['raw' => 1]), $entries);
            $stat = stat($filePath);
            $io->writeln(json_encode([
                'command' => 'logs:app',
                'query' => ['file' => $filename, 'around' => $around, 'context' => $contextLines, 'grep' => $grep],
                'file' => [
                    'name' => $filename,
                    'size' => $this->formatSize($stat['size']),
                    'modified' => date('c', $stat['mtime']),
                ],
                'entries' => $entries,
                'total' => count($entries),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
            return Command::SUCCESS;
        }

        foreach ($rawLines as $line) {
            $io->writeln($line);
        }

        return Command::SUCCESS;
    }

    private function parseLine(string $line): array
    {
        // Monolog format
        if (preg_match(self::MONOLOG_PATTERN, $line, $m)) {
            return [
                'timestamp' => $m[1],
                'channel' => $m[2],
                'level' => $m[3],
                'message' => $m[4],
                'raw' => $line,
            ];
        }

        // JSON log format
        if (preg_match(self::JSON_LOG_PATTERN, $line)) {
            $data = json_decode($line, true);
            if (is_array($data)) {
                return [
                    'timestamp' => $data['timestamp'] ?? $data['datetime'] ?? null,
                    'channel' => $data['channel'] ?? null,
                    'level' => strtoupper($data['level'] ?? $data['level_name'] ?? ''),
                    'message' => $data['message'] ?? '',
                    'context' => $data['context'] ?? null,
                    'raw' => $line,
                ];
            }
        }

        // Plain text
        return [
            'message' => $line,
            'raw' => $line,
        ];
    }

    private function parseDateTime(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }

        // Relative: -1h, -30m, -2d
        if (preg_match('/^-(\d+)([smhd])$/', $value, $m)) {
            $multipliers = ['s' => 1, 'm' => 60, 'h' => 3600, 'd' => 86400];
            return time() - ((int) $m[1] * $multipliers[$m[2]]);
        }

        $ts = strtotime($value);
        return $ts !== false ? $ts : null;
    }

    private function formatSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 1) . ' MB';
    }

    private function estimateLines(string $path): ?int
    {
        $size = filesize($path);
        if ($size === false || $size === 0) {
            return 0;
        }

        // Sample first 4KB to estimate avg line length
        $sample = file_get_contents($path, false, null, 0, 4096);
        if ($sample === false || $sample === '') {
            return null;
        }

        $sampleLines = substr_count($sample, "\n");
        if ($sampleLines === 0) {
            return 1;
        }

        $avgLineLen = strlen($sample) / $sampleLines;
        return (int) round($size / $avgLineLen);
    }
}
