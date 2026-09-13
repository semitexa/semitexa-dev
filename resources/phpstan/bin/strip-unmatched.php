<?php
/**
 * Read PHPStan strict-mode JSON output, find all "ignore.unmatched" identifiers,
 * extract the (regex, identifier, path) tuples, then strip matching baseline entries.
 *
 * Match key: identifier + path + raw regex (the message in the baseline is the same
 * string PHPStan reports inside the unmatched-ignore notice, so we extract it.)
 */
declare(strict_types=1);

$baseline = $argv[1] ?? 'phpstan-baseline.neon';
$jsonFile = $argv[2] ?? '/tmp/strict.json';
$out = $argv[3] ?? $baseline . '.cleaned';

$json = json_decode(file_get_contents($jsonFile), true);
if (!is_array($json) || !isset($json['files'])) {
    fwrite(STDERR, "bad JSON\n");
    exit(1);
}

$projectPrefix = '/var/www/html/';

// (path|identifier|escaped-message) => true
$unmatched = [];
foreach ($json['files'] as $absPath => $fileBlock) {
    $relPath = str_starts_with($absPath, $projectPrefix)
        ? substr($absPath, strlen($projectPrefix))
        : $absPath;
    foreach ($fileBlock['messages'] ?? [] as $m) {
        if (($m['identifier'] ?? null) !== 'ignore.unmatched') continue;
        $msg = $m['message'];
        // Pattern: "Ignored error pattern #^...$# (identifier) in path ... was not matched in reported errors."
        if (!preg_match('/^Ignored error pattern (#\^.*?\$#) \(([\w\.]+)\) in path (.+?) was not matched in reported errors\.$/', $msg, $mm)) {
            // Some unmatched messages may not include the identifier. Fall back to regex only.
            if (!preg_match('/^Ignored error pattern (#\^.*?\$#) in path (.+?) was not matched in reported errors\.$/', $msg, $mm2)) {
                fwrite(STDERR, "skip unparseable: $msg\n");
                continue;
            }
            $regex = $mm2[1];
            $identifier = null;
            $path = $mm2[2];
        } else {
            $regex = $mm[1];
            $identifier = $mm[2];
            $path = $mm[3];
        }
        $relPathFromMsg = str_starts_with($path, $projectPrefix) ? substr($path, strlen($projectPrefix)) : $path;
        $key = $relPathFromMsg . '|' . ($identifier ?? '') . '|' . $regex;
        $unmatched[$key] = true;
    }
}

echo "Unmatched count from JSON: " . count($unmatched) . "\n";

// Now walk the baseline and strip matching entries
$lines = file($baseline, FILE_IGNORE_NEW_LINES);
if ($lines === false) {
    fwrite(STDERR, "cannot read baseline\n");
    exit(1);
}

$total = count($lines);
$keep = [$lines[0], $lines[1]];
$removed = 0;
$removedById = [];
$keptById = [];

$i = 2;
while ($i < $total) {
    $line = $lines[$i];
    if (preg_match('/^\t\t-$/', $line)) {
        $block = [$line];
        $j = $i + 1;
        while ($j < $total) {
            $next = $lines[$j];
            if (preg_match('/^\t\t-$/', $next)) break;
            $block[] = $next;
            $j++;
            if ($next === '') break;
        }
        // Extract regex, identifier, path from block
        $regex = $identifier = $path = null;
        foreach ($block as $bl) {
            if (preg_match("/^\t\t\tmessage:\s*'(.+)'$/", $bl, $m)) {
                // YAML single-quoted: escape '' to '
                $regex = str_replace("''", "'", $m[1]);
            }
            if (preg_match('/^\t\t\tidentifier:\s*(\S+)$/', $bl, $m)) {
                $identifier = $m[1];
            }
            if (preg_match('/^\t\t\tpath:\s*(.+)$/', $bl, $m)) {
                $path = trim($m[1]);
            }
        }
        $key = ($path ?? '') . '|' . ($identifier ?? '') . '|' . ($regex ?? '');
        if (isset($unmatched[$key])) {
            $removed++;
            $removedById[$identifier ?? '???'] = ($removedById[$identifier ?? '???'] ?? 0) + 1;
        } else {
            $keptById[$identifier ?? '???'] = ($keptById[$identifier ?? '???'] ?? 0) + 1;
            foreach ($block as $bl) $keep[] = $bl;
        }
        $i = $j;
    } else {
        $keep[] = $line;
        $i++;
    }
}

file_put_contents($out, implode("\n", $keep) . "\n");

echo "Removed $removed unmatched baseline entries.\n";
arsort($removedById);
foreach ($removedById as $id => $c) {
    echo sprintf("  %4d  %s\n", $c, $id);
}
