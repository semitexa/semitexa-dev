<?php
/**
 * Remove baseline entries whose `path:` no longer exists on disk.
 * Operates on phpstan-baseline.neon in-place by writing to a new file.
 */
declare(strict_types=1);

$baseline = $argv[1] ?? 'phpstan-baseline.neon';
$out = $argv[2] ?? $baseline . '.cleaned';

$lines = file($baseline, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "cannot read $baseline\n");
    exit(1);
}

$total = count($lines);
$keep = [];
$removed = 0;
$removedByPath = [];

// First two lines are header (parameters: / ignoreErrors:)
$keep[] = $lines[0];
$keep[] = $lines[1];

$i = 2;
while ($i < $total) {
    $line = $lines[$i];
    if (preg_match('/^\t\t-$/', $line)) {
        // Entry starts. Collect until next blank line or next entry header.
        $block = [$line];
        $j = $i + 1;
        while ($j < $total) {
            $next = $lines[$j];
            if (preg_match('/^\t\t-$/', $next)) {
                break;
            }
            $block[] = $next;
            $j++;
            // Blank line separator after entry
            if ($next === '') {
                break;
            }
        }
        // Find path: line in block
        $path = null;
        foreach ($block as $bl) {
            if (preg_match('/^\t\t\tpath:\s*(.+)$/', $bl, $m)) {
                $path = trim($m[1]);
                break;
            }
        }
        if ($path !== null && !file_exists($path)) {
            $removed++;
            $removedByPath[$path] = ($removedByPath[$path] ?? 0) + 1;
        } else {
            foreach ($block as $bl) {
                $keep[] = $bl;
            }
        }
        $i = $j;
    } else {
        $keep[] = $line;
        $i++;
    }
}

file_put_contents($out, implode("\n", $keep) . "\n");

echo "Removed $removed stale baseline entries:\n";
ksort($removedByPath);
foreach ($removedByPath as $p => $c) {
    echo sprintf("  %4d  %s\n", $c, $p);
}
