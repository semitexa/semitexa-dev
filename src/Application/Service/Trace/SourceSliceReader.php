<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;
use Semitexa\Core\Attribute\InjectAsReadonly;
use Semitexa\Core\Support\ProjectRoot;

/**
 * The source code behind a class the trace named — one method, or the class.
 *
 * ## Why it reads at view time
 *
 * A trace records that `ThingHandler` ran. It does not copy the handler's code
 * into the recording: the code is a property of the working copy, not of the
 * request, and a copy would show whatever the file said when the request was
 * made rather than what the developer is about to edit. So the slice is read
 * from disk when the page is opened, and it is always the current file.
 *
 * ## Where the line numbers come from
 *
 * Reflection first. `ReflectionMethod::getStartLine()` / `getEndLine()` are
 * exact, method-capable and never stale — the project graph is class-level
 * (it emits no method nodes) and is only as fresh as the last
 * `ai:review-graph:generate`. The graph is the fallback for a class the
 * autoloader cannot produce (deleted since the trace, outside the autoload
 * map, or failing to load), and then only the whole class is available.
 *
 * ## What it refuses
 *
 * The class name arrives from a query string. Anything that is not the exact
 * shape of a PHP class name is rejected before it reaches the autoloader, and
 * whatever file the bounds resolve to must sit inside the project root after
 * symlinks are resolved. A slice reader that can be pointed at an arbitrary
 * path is a file-read endpoint, whatever it is called.
 *
 * Never throws: a source view that breaks the trace page it decorates is
 * worse than none. Every failure is `null`.
 */
#[AsService]
final class SourceSliceReader
{
    /**
     * Beyond this a page stops being a slice and becomes the file. The reader
     * cuts and says so; the viewer can offer the class view for the rest.
     */
    public const MAX_LINES = 400;

    /**
     * The graph indexer runs in a container whose root is not the developer's
     * checkout. When a graph path is not under the current root, the run is
     * relocated by the first project-level directory in it — the only part of
     * the path both machines agree on.
     */
    private const RELOCATABLE_DIRS = ['packages', 'src', 'vendor', 'Platform'];

    #[InjectAsReadonly]
    protected TraceGraphReader $graph;

    /**
     * @param string      $fqcn     class to read
     * @param string|null $method   method to narrow to; null means the whole class.
     *                              A method that does not exist falls back to
     *                              the class rather than to nothing — the reader
     *                              asked "show me where this ran" and the class
     *                              is still the right answer.
     * @param int         $maxLines line cap, {@see MAX_LINES} by default
     */
    public function slice(string $fqcn, ?string $method = null, int $maxLines = self::MAX_LINES): ?SourceSlice
    {
        try {
            if (!$this->looksLikeClass($fqcn)) {
                return null;
            }

            if ($method !== null && !$this->looksLikeIdentifier($method)) {
                $method = null;
            }

            return $this->fromReflection($fqcn, $method, $maxLines)
                ?? $this->fromGraph($fqcn, $maxLines);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The first of several candidate methods that exists, else the class.
     *
     * For a class the trace named without a method: the framework's entry
     * convention ({@see EntryMethodCatalog}) gives a short ordered list, and
     * the first one the class actually declares (or inherits) is the one shown.
     *
     * @param list<string> $methods
     */
    public function sliceAny(string $fqcn, array $methods, int $maxLines = self::MAX_LINES): ?SourceSlice
    {
        foreach ($methods as $method) {
            $slice = $this->slice($fqcn, $method, $maxLines);
            if ($slice === null) {
                // The class itself is unreadable; no other method will fare better.
                return null;
            }
            if ($slice->method !== null) {
                return $slice;
            }
        }

        return $this->slice($fqcn, null, $maxLines);
    }

    private function fromReflection(string $fqcn, ?string $method, int $maxLines): ?SourceSlice
    {
        try {
            // Autoloads. The name has already been checked for shape, and this
            // runs only where the trace viewer itself is enabled (dev).
            $class = new \ReflectionClass($fqcn);
        } catch (\Throwable) {
            return null;
        }

        if ($class->isInternal()) {
            return null;
        }

        $shown = null;
        $file = $class->getFileName();
        $start = $class->getStartLine();
        $end = $class->getEndLine();

        if ($method !== null && $class->hasMethod($method)) {
            $reflected = $class->getMethod($method);
            // A method inherited from a parent lives in the parent's file, and
            // that is the file worth showing — the child has no such lines.
            if (!$reflected->isInternal() && $reflected->getFileName() !== false) {
                $shown = $reflected->getName();
                $file = $reflected->getFileName();
                $start = $reflected->getStartLine();
                $end = $reflected->getEndLine();
            }
        }

        if ($file === false || $start === false || $end === false) {
            return null;
        }

        return $this->read($fqcn, $shown, $file, $start, $end, $maxLines, 'reflection');
    }

    private function fromGraph(string $fqcn, int $maxLines): ?SourceSlice
    {
        // Uninitialised outside the container (unit tests, or a project without
        // the ORM the graph rides on). isset() is false for an unset typed
        // property, so the fallback simply does not exist there.
        if (!isset($this->graph)) {
            return null;
        }

        $node = $this->graph->describe($fqcn);
        if ($node === null || $node['file'] === '' || $node['line'] < 1) {
            return null;
        }

        $file = $this->locate($node['file']);
        if ($file === null) {
            return null;
        }

        // The graph's describe() drops endLine. Read to the end of the file and
        // let the cap decide; a class-level fallback is coarse by nature.
        $end = $this->lineCount($file);
        if ($end === null) {
            return null;
        }

        return $this->read($fqcn, null, $file, $node['line'], $end, $maxLines, 'graph');
    }

    private function read(
        string $fqcn,
        ?string $method,
        string $file,
        int $start,
        int $end,
        int $maxLines,
        string $origin,
    ): ?SourceSlice {
        $confined = $this->confine($file);
        if ($confined === null) {
            return null;
        }

        $all = @file($confined, FILE_IGNORE_NEW_LINES);
        if ($all === false || $all === []) {
            return null;
        }

        $start = max(1, $start);
        $end = min(max($start, $end), count($all));
        $maxLines = max(1, $maxLines);

        $truncated = false;
        if ($end - $start + 1 > $maxLines) {
            $end = $start + $maxLines - 1;
            $truncated = true;
        }

        return new SourceSlice(
            fqcn: $fqcn,
            method: $method,
            file: $this->relative($confined),
            startLine: $start,
            endLine: $end,
            lines: array_values(array_slice($all, $start - 1, $end - $start + 1)),
            truncated: $truncated,
            origin: $origin,
        );
    }

    /**
     * The real path of $file, or null when it is not a regular file inside the
     * project root. Symlinks are resolved on BOTH sides: `vendor/semitexa/*`
     * points into `packages/`, and a root reached through a symlink would
     * otherwise never prefix-match its own files.
     */
    private function confine(string $file): ?string
    {
        $real = realpath($file);
        $root = realpath(ProjectRoot::get());

        if ($real === false || $root === false || !is_file($real)) {
            return null;
        }

        return str_starts_with($real, rtrim($root, '/') . '/') ? $real : null;
    }

    /**
     * A graph path, made openable on this machine.
     */
    private function locate(string $file): ?string
    {
        $root = rtrim(ProjectRoot::get(), '/');

        if (!str_starts_with($file, '/')) {
            return $root . '/' . $file;
        }

        if (is_file($file) && $this->confine($file) !== null) {
            return $file;
        }

        foreach (self::RELOCATABLE_DIRS as $dir) {
            $pos = strpos($file, '/' . $dir . '/');
            if ($pos !== false) {
                return $root . substr($file, $pos);
            }
        }

        return null;
    }

    private function lineCount(string $file): ?int
    {
        $confined = $this->confine($file);
        if ($confined === null) {
            return null;
        }

        $all = @file($confined);

        return $all === false ? null : count($all);
    }

    private function relative(string $file): string
    {
        $root = realpath(ProjectRoot::get());
        if ($root === false) {
            return $file;
        }

        $root = rtrim($root, '/') . '/';

        return str_starts_with($file, $root) ? substr($file, strlen($root)) : $file;
    }

    /** Same shape rule as the trace renderer's link detection. */
    private function looksLikeClass(string $value): bool
    {
        return str_contains($value, '\\')
            && preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)+$/', $value) === 1;
    }

    private function looksLikeIdentifier(string $value): bool
    {
        return preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*$/', $value) === 1;
    }
}
