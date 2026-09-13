<?php
/**
 * Sync stale `count:` fields in phpstan-baseline.neon entries that PHPStan reports
 * as "expected to occur N times, but occurred only M times".
 */
declare(strict_types=1);

$baseline = $argv[1] ?? 'phpstan-baseline.neon';
$jsonFile = $argv[2] ?? '/tmp/strict.json';
$out = $argv[3] ?? $baseline . '.cleaned';

$json = json_decode(file_get_contents($jsonFile), true);
$projectPrefix = '/var/www/html/';

$updates = []; // path|identifier|regex => newCount
$pattern = '/^Ignored error pattern (#\^.*?\$#) \(([\w\.]+)\) in path (.+?) is expected to occur (\d+) times?, but occurred only (\d+) times?\.$/';

foreach ($json['files'] as $absPath => $fileBlock) {
    foreach ($fileBlock['messages'] ?? [] as $m) {
        $idF = $m['identifier'] ?? null;
        if ($idF !== 'ignore.unmatched' && $idF !== 'ignore.count') continue;
        if (!preg_match($pattern, $m['message'], $mm)) continue;
        $regex = $mm[1];
        $identifier = $mm[2];
        $path = $mm[3];
        $newCount = (int) $mm[5];
        $relPath = str_starts_with($path, $projectPrefix) ? substr($path, strlen($projectPrefix)) : $path;
        $key = $relPath . '|' . $identifier . '|' . $regex;
        $updates[$key] = $newCount;
    }
}

echo "Count updates from JSON: " . count($updates) . "\n";

$lines = file($baseline, FILE_IGNORE_NEW_LINES);
$total = count($lines);
$keep = [$lines[0], $lines[1]];
$updated = 0;

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
        $regex = $identifier = $path = null;
        $countLineIdx = null;
        foreach ($block as $idx => $bl) {
            if (preg_match("/^\t\t\tmessage:\s*'(.+)'$/", $bl, $m)) {
                $regex = str_replace("''", "'", $m[1]);
            }
            if (preg_match('/^\t\t\tidentifier:\s*(\S+)$/', $bl, $m)) {
                $identifier = $m[1];
            }
            if (preg_match('/^\t\t\tcount:\s*(\d+)$/', $bl, $m)) {
                $countLineIdx = $idx;
            }
            if (preg_match('/^\t\t\tpath:\s*(.+)$/', $bl, $m)) {
                $path = trim($m[1]);
            }
        }
        $key = ($path ?? '') . '|' . ($identifier ?? '') . '|' . ($regex ?? '');
        if (isset($updates[$key]) && $countLineIdx !== null) {
            $newCount = $updates[$key];
            $block[$countLineIdx] = "\t\t\tcount: " . $newCount;
            $updated++;
        }
        foreach ($block as $bl) $keep[] = $bl;
        $i = $j;
    } else {
        $keep[] = $line;
        $i++;
    }
}

file_put_contents($out, implode("\n", $keep) . "\n");
echo "Updated $updated baseline entry counts.\n";
