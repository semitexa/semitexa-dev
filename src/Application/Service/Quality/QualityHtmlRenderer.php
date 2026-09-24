<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Quality;

use Semitexa\Core\Attribute\AsService;

/**
 * `/__quality`: the ledger as a page a person reads.
 *
 * The same data `ai:quality` gives an agent — every metric, where it stands,
 * how it got there, what it cannot see, and what to improve next — with the
 * history drawn as a line, because a trend is the thing JSON is worst at
 * showing. Server-rendered and self-contained, like `/__trace`: dev must not
 * depend on the ssr asset pipeline.
 */
#[AsService]
final class QualityHtmlRenderer
{
    public function render(string $projectRoot): string
    {
        $baseline = $this->json($projectRoot . '/' . QualityLedger::BASELINE);
        $metrics = is_array($baseline['metrics'] ?? null) ? $baseline['metrics'] : [];
        if ($metrics === []) {
            return $this->page('<p class="empty">No quality ledger in this project yet. Run <code>bin/semitexa ai:quality record</code> to start one.</p>');
        }

        $history = $this->history($projectRoot . '/' . QualityLedger::HISTORY);
        $deliberate = is_array($baseline['deliberate'] ?? null) ? $baseline['deliberate'] : [];

        $body = '<header><h1>Quality ledger</h1><p class="lede">Numbers that may only go down. '
            . 'A regression fails <code>ai:verify</code>; an improvement fails too until <code>ai:quality record</code> locks it in; '
            . 'the only way up is <code>ai:quality accept --reason</code>, and the reason is kept below.</p></header>';

        $body .= $this->nextSection((new QualityAdvisor($projectRoot))->targets(5));

        $body .= '<section class="cards">';
        foreach ($metrics as $id => $m) {
            $body .= $this->card((string) $id, is_array($m) ? $m : [], $history[(string) $id] ?? []);
        }
        $body .= '</section>';

        $body .= $this->deliberateSection($deliberate);

        return $this->page($body);
    }

    /**
     * @param list<array<string, mixed>> $targets
     */
    private function nextSection(array $targets): string
    {
        if ($targets === []) {
            return '';
        }
        $rows = '';
        foreach ($targets as $i => $t) {
            $rows .= sprintf(
                '<li><span class="n">%d</span><b>%s</b> <span class="dim">in</span> <code>%s</code> <span class="count">%d</span><small>%s</small></li>',
                $i + 1,
                $this->e((string) $t['key']),
                $this->e((string) $t['metric']),
                (int) $t['count'],
                $this->e((string) $t['why']),
            );
        }

        return '<section class="next"><h2>Improve next <span class="dim">ai:quality next</span></h2><ol>' . $rows . '</ol></section>';
    }

    /**
     * @param array<string, mixed>      $m
     * @param list<array{at: string, total: int, event: string}> $points
     */
    private function card(string $id, array $m, array $points): string
    {
        $breakdown = is_array($m['breakdown'] ?? null) ? $m['breakdown'] : [];
        arsort($breakdown);
        $rows = '';
        foreach (array_slice($breakdown, 0, 8, true) as $key => $count) {
            $rows .= sprintf('<tr><td>%s</td><td class="num">%d</td></tr>', $this->e((string) $key), (int) $count);
        }
        $more = count($breakdown) > 8 ? sprintf('<p class="dim">and %d more</p>', count($breakdown) - 8) : '';

        return sprintf(
            '<article class="card"><div class="top"><h3>%s</h3><div class="total">%d</div></div>%s'
            . '<p class="sees"><b>Sees</b> %s</p><p class="blind"><b>Blind to</b> %s</p>'
            . '<table>%s</table>%s</article>',
            $this->e($id),
            (int) ($m['total'] ?? 0),
            $this->sparkline($points),
            $this->e((string) ($m['sees'] ?? '')),
            $this->e((string) ($m['blind'] ?? '')),
            $rows,
            $more,
        );
    }

    /**
     * The metric's recorded totals over time. A ledger only writes on change,
     * so this is a step line: flat means nobody moved it.
     *
     * @param list<array{at: string, total: int, event: string}> $points
     */
    private function sparkline(array $points): string
    {
        if (count($points) < 2) {
            return '<p class="trend dim">' . (count($points) === 1 ? 'one reading so far — the trend starts at the next change' : 'no history') . '</p>';
        }
        $w = 280;
        $h = 48;
        $max = max(array_column($points, 'total')) ?: 1;
        $n = count($points) - 1;
        $path = '';
        $dots = '';
        foreach ($points as $i => $p) {
            $x = round($i / $n * ($w - 8) + 4, 1);
            $y = round($h - 4 - ($p['total'] / $max) * ($h - 8), 1);
            $path .= ($i === 0 ? 'M' : ' H' . $x . ' V') . ($i === 0 ? "{$x} {$y}" : $y);
            $dots .= sprintf(
                '<circle cx="%s" cy="%s" r="3" class="%s"><title>%s %s: %d</title></circle>',
                $x,
                $y,
                $this->e($p['event']),
                $this->e(substr($p['at'], 0, 16)),
                $this->e($p['event']),
                $p['total'],
            );
        }
        $first = $points[0]['total'];
        $last = $points[$n]['total'];
        $delta = $last - $first;

        return sprintf(
            '<div class="trend"><svg viewBox="0 0 %d %d" width="100%%" height="%d" role="img" aria-label="history"><path d="%s"/>%s</svg>'
            . '<span class="%s">%s%d since %s</span></div>',
            $w,
            $h,
            $h,
            $path,
            $dots,
            $delta < 0 ? 'down' : ($delta > 0 ? 'up' : 'dim'),
            $delta > 0 ? '+' : '',
            $delta,
            $this->e(substr($points[0]['at'], 0, 10)),
        );
    }

    /**
     * @param list<mixed> $deliberate
     */
    private function deliberateSection(array $deliberate): string
    {
        if ($deliberate === []) {
            return '<section class="deliberate"><h2>Raised deliberately</h2><p class="dim">Nothing yet.</p></section>';
        }
        $rows = '';
        foreach (array_reverse($deliberate) as $d) {
            if (!is_array($d)) {
                continue;
            }
            $rows .= sprintf(
                '<tr><td>%s</td><td><code>%s</code></td><td class="num">%d → %d</td><td>%s</td></tr>',
                $this->e((string) ($d['at'] ?? '')),
                $this->e((string) ($d['metric'] ?? '')),
                (int) ($d['from'] ?? 0),
                (int) ($d['to'] ?? 0),
                $this->e((string) ($d['reason'] ?? '')),
            );
        }

        return '<section class="deliberate"><h2>Raised deliberately</h2><table>' . $rows . '</table></section>';
    }

    /**
     * @return array<string, list<array{at: string, total: int, event: string}>>
     */
    private function history(string $path): array
    {
        $out = [];
        foreach (is_file($path) ? (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []) : [] as $line) {
            $e = json_decode($line, true);
            if (is_array($e) && is_string($e['metric'] ?? null) && is_int($e['total'] ?? null)) {
                $out[$e['metric']][] = ['at' => (string) ($e['at'] ?? ''), 'total' => $e['total'], 'event' => (string) ($e['event'] ?? '')];
            }
        }

        return $out;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $path): array
    {
        $data = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;

        return is_array($data) ? $data : [];
    }

    private function e(string $s): string
    {
        return htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function page(string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>Quality ledger · Semitexa</title><style>' . self::CSS . '</style></head><body><main>'
            . $body . '</main></body></html>';
    }

    private const CSS = <<<'CSS'
*{box-sizing:border-box}
:root{--bg:#0b0f17;--panel:#111827;--line:#1f2937;--text:#e5e7eb;--dim:#8b95a7;--accent:#5b9dff;--ok:#3ddc97;--warn:#ffb454;--danger:#ff6b6b;
  --mono:ui-monospace,SFMono-Regular,Menlo,monospace;color-scheme:dark}
body{margin:0;background:var(--bg);color:var(--text);font:14px/1.5 system-ui,-apple-system,Segoe UI,sans-serif}
main{max-width:1100px;margin:0 auto;padding:32px 16px 64px}
h1{margin:0 0 6px;font-size:24px} h2{font-size:15px;margin:28px 0 10px} h3{margin:0;font:600 14px var(--mono)}
.lede,.dim{color:var(--dim)} code{font-family:var(--mono);font-size:12px;color:var(--accent)}
.next ol{list-style:none;margin:0;padding:0;border:1px solid var(--line);border-radius:10px;background:var(--panel)}
.next li{display:flex;flex-wrap:wrap;gap:4px 8px;align-items:baseline;padding:10px 14px;border-bottom:1px solid var(--line)}
.next li:last-child{border-bottom:0} .next .n{color:var(--dim);font-family:var(--mono)} .next .count{font:700 13px var(--mono);color:var(--warn)}
.next .n{width:20px} .next small{color:var(--dim);flex-basis:100%;padding-left:28px}
.cards{display:grid;grid-template-columns:repeat(auto-fill,minmax(320px,1fr));gap:14px;margin-top:20px}
.card{border:1px solid var(--line);border-radius:10px;background:var(--panel);padding:16px}
.top{display:flex;justify-content:space-between;align-items:baseline} .total{font:700 28px var(--mono)}
.sees,.blind{margin:6px 0;font-size:12.5px;color:var(--dim)} .sees b,.blind b{color:var(--text);font-weight:600}
.blind b{color:var(--warn)}
table{width:100%;border-collapse:collapse;margin-top:10px;font-size:12.5px}
td{padding:4px 0;border-top:1px solid var(--line);word-break:break-word} td.num{text-align:right;font-family:var(--mono);white-space:nowrap;padding-left:12px}
.trend{margin:10px 0 4px;font-size:12px} .trend svg{display:block}
.trend path{fill:none;stroke:var(--accent);stroke-width:2}
.trend circle{fill:var(--accent)} .trend circle.accept{fill:var(--warn)} .trend circle.new{fill:var(--dim)}
.trend .down{color:var(--ok);font:600 12px var(--mono)} .trend .up{color:var(--danger);font:600 12px var(--mono)} .trend span.dim{font:12px var(--mono)}
.deliberate td:first-child{white-space:nowrap;color:var(--dim);padding-right:12px}
.empty{color:var(--dim)}
@media (max-width:600px){.next small{padding-left:0}}
CSS;
}
