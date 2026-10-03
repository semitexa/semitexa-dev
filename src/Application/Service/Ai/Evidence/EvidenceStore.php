<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Ai\Evidence;

/**
 * Where review evidence lives while it is private: `var/evidence/<id>/`, the
 * file beside its passport (meta.json). Before this every tool kept its own
 * folder — var/e2e-proof, var/os-dev-shots, var/review-graph — with nothing
 * saying what a file showed, whether it may leave the machine, or when it
 * goes; screenshots from June were still there in October, ready to be
 * attached somewhere as "proof" (ep-agent-private-review-evidence).
 *
 * Expired evidence is removed by every `ai:evidence add` and by `ai:evidence prune`.
 * The directory is in the scaffold .gitignore.
 */
final class EvidenceStore
{
    public const DIR = 'var/evidence';
    /**
     * Where a tool that cannot depend on this package drops evidence: the file
     * plus `<file>.evidence.json` ({"schema":"semitexa.evidence-inbox/v1",
     * "kind","data","note","ttl_days","created_by"}). Adopted — moved into the
     * store with a passport — by every ai:evidence command.
     */
    public const INBOX = 'var/evidence/inbox';
    public const INBOX_SCHEMA = 'semitexa.evidence-inbox/v1';
    private const SIDECAR = '.evidence.json';
    public const DEFAULT_TTL_DAYS = 14;
    public const MAX_TTL_DAYS = 90;
    private const META = 'meta.json';
    /** Names the passport writes beside the file: a file called so would be overwritten by it. */
    private const RESERVED = ['meta.json', 'meta.json.tmp'];
    private const ID = '/^ev-\d{8}-\d{6}-[0-9a-f]{6}$/';

    /**
     * Folders tools wrote evidence into before this store existed. Listed so
     * nothing is invisible; never pruned from here — that is the operator's call.
     *
     * @var array<string, EvidenceKind>
     */
    public const UNREGISTERED_SOURCES = [
        'var/review-graph' => EvidenceKind::GraphExport,
        'var/e2e-proof' => EvidenceKind::Screenshot,
        'var/os-dev-shots' => EvidenceKind::Screenshot,
        'var/test-results' => EvidenceKind::Screenshot,
    ];

    private readonly string $root;

    public function __construct(string $projectRoot)
    {
        $this->root = rtrim($projectRoot, '/');
    }

    public function dir(): string
    {
        return $this->root . '/' . self::DIR;
    }

    /**
     * @throws \InvalidArgumentException on a file the store cannot take
     */
    public function add(string $file, EvidenceKind $kind, EvidenceData $data, string $note, string $createdBy, int $ttlDays, bool $move, \DateTimeImmutable $now): EvidenceRecord
    {
        if ($ttlDays < 1 || $ttlDays > self::MAX_TTL_DAYS) {
            throw new \InvalidArgumentException(sprintf('--ttl-days must be 1..%d: evidence is kept for a review, not archived.', self::MAX_TTL_DAYS));
        }
        $path = str_starts_with($file, '/') ? $file : $this->root . '/' . $file;
        // Moving a link would move what it points at — an asset out of src/,
        // later pruned with the evidence.
        if ($move && is_link($path)) {
            throw new \InvalidArgumentException($file . ' is a link: record it without --move, or move the file itself.');
        }
        $real = realpath($path);
        if ($real === false || !is_file($real)) {
            throw new \InvalidArgumentException(sprintf(
                'No file at %s. The command runs inside the project container: copy a file from elsewhere (an agent scratchpad, ~/Downloads) into var/tmp/ first.',
                $file,
            ));
        }
        if (!str_starts_with($real, $this->root . '/')) {
            throw new \InvalidArgumentException($file . ' is outside the project: copy it into var/tmp/ first.');
        }
        if (str_starts_with($real, $this->dir() . '/') && !str_starts_with($real, $this->root . '/' . self::INBOX . '/')) {
            throw new \InvalidArgumentException($file . ' is already in the evidence store.');
        }

        if (in_array(basename($real), self::RESERVED, true)) {
            throw new \InvalidArgumentException(basename($real) . ' is the name of the passport stored beside the file: rename it first.');
        }

        $id = 'ev-' . $now->format('Ymd-His') . '-' . bin2hex(random_bytes(3));
        $target = $this->dir() . '/' . $id;
        if (!@mkdir($target, 0700, true) && !is_dir($target)) {
            throw new \RuntimeException('Cannot create ' . $target);
        }
        $name = basename($real);
        $stored = $target . '/' . $name;
        if (!($move ? @rename($real, $stored) : @copy($real, $stored))) {
            self::remove($target);
            throw new \RuntimeException(sprintf('Cannot %s %s into the evidence store', $move ? 'move' : 'copy', $file));
        }

        try {
            $record = new EvidenceRecord(
                id: $id,
                kind: $kind,
                data: $data,
                file: $name,
                sha256: (string) hash_file('sha256', $stored),
                bytes: (int) filesize($stored),
                createdAt: $now,
                expiresAt: $now->modify('+' . $ttlDays . ' days'),
                createdBy: $createdBy,
                source: substr($real, strlen($this->root) + 1),
                note: $note,
            );
            $this->save($record);
        } catch (\Throwable $e) {
            // No passport, no evidence: put a moved file back where it was
            // rather than strand it in a folder nothing will ever prune.
            if ($move) {
                @rename($stored, $real);
            }
            self::remove($target);
            throw $e;
        }

        return $record;
    }

    /**
     * @return array{adopted: list<EvidenceRecord>, rejected: array<string, string>} rejected: inbox file => why it stays
     */
    public function adopt(\DateTimeImmutable $now): array
    {
        $adopted = [];
        $rejected = [];
        foreach (glob($this->root . '/' . self::INBOX . '/*' . self::SIDECAR) ?: [] as $sidecar) {
            $file = substr($sidecar, 0, -strlen(self::SIDECAR));
            $name = basename($file);
            $hint = json_decode((string) @file_get_contents($sidecar), true);
            $kind = is_array($hint) ? EvidenceKind::tryFrom((string) ($hint['kind'] ?? '')) : null;
            $data = is_array($hint) ? EvidenceData::tryFrom((string) ($hint['data'] ?? '')) : null;
            if (!is_array($hint) || ($hint['schema'] ?? null) !== self::INBOX_SCHEMA || $kind === null || $data === null) {
                $rejected[$name] = 'its ' . basename($sidecar) . ' is not a ' . self::INBOX_SCHEMA . ' hint with a kind and data';
                continue;
            }
            if (!is_file($file)) {
                $rejected[$name] = 'the file its hint describes is missing';
                continue;
            }
            $ttl = (int) ($hint['ttl_days'] ?? self::DEFAULT_TTL_DAYS);
            try {
                $adopted[] = $this->add(substr($file, strlen($this->root) + 1), $kind, $data, (string) ($hint['note'] ?? ''), (string) ($hint['created_by'] ?? 'inbox'), $ttl, true, $now);
                @unlink($sidecar);
            } catch (\InvalidArgumentException|\RuntimeException $e) {
                $rejected[$name] = $e->getMessage();
            }
        }

        return ['adopted' => $adopted, 'rejected' => $rejected];
    }

    /** Files a tool dropped in the inbox that no ai:evidence command has adopted yet. */
    public function inboxPending(): int
    {
        return count(glob($this->root . '/' . self::INBOX . '/*' . self::SIDECAR) ?: []);
    }

    public function save(EvidenceRecord $record): void
    {
        $meta = $this->dir() . '/' . $record->id . '/' . self::META;
        $tmp = $meta . '.tmp';
        if (@file_put_contents($tmp, json_encode($record->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n") === false || !@rename($tmp, $meta)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . $meta);
        }
    }

    public function find(string $id): ?EvidenceRecord
    {
        $dir = $this->dir() . '/' . $id;
        if (preg_match(self::ID, $id) !== 1 || is_link($dir)) {
            return null;
        }

        return $this->read($dir);
    }

    public function fileOf(EvidenceRecord $record): string
    {
        return $this->dir() . '/' . $record->id . '/' . $record->file;
    }

    /**
     * @return array{records: list<EvidenceRecord>, unreadable: list<string>} unreadable = entry names without a valid passport
     */
    public function all(): array
    {
        $records = [];
        $unreadable = [];
        foreach (glob($this->dir() . '/*', GLOB_ONLYDIR) ?: [] as $dir) {
            // A link is not a folder of the store: following it, prune would
            // empty wherever it points, and the panel would serve it.
            if ($dir === $this->root . '/' . self::INBOX || is_link($dir)) {
                continue;
            }
            $record = preg_match(self::ID, basename($dir)) === 1 ? $this->read($dir) : null;
            if ($record === null) {
                $unreadable[] = basename($dir);
                continue;
            }
            $records[] = $record;
        }
        usort($records, static fn (EvidenceRecord $a, EvidenceRecord $b): int => [$a->createdAt, $a->id] <=> [$b->createdAt, $b->id]);
        sort($unreadable);

        return ['records' => $records, 'unreadable' => $unreadable];
    }

    /**
     * Removes what expired. A folder without a readable passport is reported
     * by `all()`, never removed: nobody can say what it holds or whose it is.
     *
     * @return list<EvidenceRecord> what was (or, dry, would be) removed
     */
    public function prune(\DateTimeImmutable $now, bool $dryRun): array
    {
        $removed = [];
        foreach ($this->all()['records'] as $record) {
            if ($record->isExpired($now) && ($dryRun || self::remove($this->dir() . '/' . $record->id))) {
                $removed[] = $record;
            }
        }

        return $removed;
    }

    /**
     * @return list<array{dir: string, kind: string, files: int, bytes: int, oldest: ?string}>
     */
    public function unregistered(): array
    {
        $found = [];
        foreach (self::UNREGISTERED_SOURCES as $dir => $kind) {
            $path = $this->root . '/' . $dir;
            if (!is_dir($path)) {
                continue;
            }
            $files = 0;
            $bytes = 0;
            $oldest = null;
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS));
            foreach ($iterator as $file) {
                if (!$file instanceof \SplFileInfo || !$file->isFile() || $file->getFilename() === '.gitkeep') {
                    continue;
                }
                $files++;
                $bytes += (int) $file->getSize();
                $oldest = $oldest === null ? $file->getMTime() : min($oldest, $file->getMTime());
            }
            if ($files > 0) {
                $found[] = ['dir' => $dir, 'kind' => $kind->value, 'files' => $files, 'bytes' => $bytes, 'oldest' => $oldest === null ? null : gmdate('Y-m-d', $oldest)];
            }
        }

        return $found;
    }

    private function read(string $dir): ?EvidenceRecord
    {
        $raw = @file_get_contents($dir . '/' . self::META);
        $row = is_string($raw) ? json_decode($raw, true) : null;
        if (!is_array($row)) {
            return null;
        }
        try {
            $record = EvidenceRecord::fromArray($row);
        } catch (\UnexpectedValueException|\Exception) {
            return null;
        }

        // A passport copied into another folder must not vouch for it.
        return $record->id === basename($dir) && basename($record->file) === $record->file ? $record : null;
    }

    /** @return bool whether the folder is gone: a file it could not delete is not reported as removed */
    private static function remove(string $dir): bool
    {
        if (is_link($dir) || !is_dir($dir)) {
            return false;
        }
        // Not glob(GLOB_BRACE): musl (the Alpine image) has no GLOB_BRACE.
        foreach (scandir($dir) ?: [] as $name) {
            if ($name === '.' || $name === '..') {
                continue;
            }
            $entry = $dir . '/' . $name;
            is_dir($entry) && !is_link($entry) ? self::remove($entry) : @unlink($entry);
        }

        return @rmdir($dir);
    }
}
