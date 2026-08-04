<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * Renders a trace as a self-contained HTML page.
 *
 * No Twig, no assets, no external anything: semitexa/dev does not depend on
 * semitexa/ssr and should not start to for the sake of a debug view. Everything
 * here is inline, so the page works in a project whose asset pipeline is exactly
 * what is being debugged.
 *
 * The bars are proportional to real durations, never to a minimum-width fudge —
 * a step that took 0.01ms should look like nothing, because it was nothing. The
 * one concession is a floor of 0.15% so a measured span never renders as
 * literally invisible and gets mistaken for missing.
 */
#[AsService]
final class TraceHtmlRenderer
{
    /** Span name prefix => accent hue. Anything unrecognised falls back to slate. */
    private const HUES = [
        'request' => 210,
        'auth' => 275,
        'payload' => 35,
        'resource' => 160,
        'pipeline' => 200,
        'response' => 330,
        'orm' => 95,
    ];

    /**
     * @param list<array{file: string, recordedAt: string, path: string, method: string, totalMs: float, queries: int}> $traces
     */
    public function renderList(array $traces, string $dir): string
    {
        if ($traces === []) {
            return $this->page('Traces', $this->emptyState($dir));
        }

        $rows = '';
        foreach ($traces as $t) {
            $rows .= sprintf(
                '<a class="row" href="/__trace?file=%s">
                   <span class="method">%s</span>
                   <span class="path">%s</span>
                   <span class="chip">%s</span>
                   <span class="num">%s ms</span>
                   <span class="when">%s</span>
                 </a>',
                rawurlencode($t['file']),
                $this->e($t['method']),
                $this->e($t['path']),
                $t['queries'] > 0 ? $t['queries'] . ' queries' : 'no queries',
                $this->ms($t['totalMs']),
                $this->e(substr($t['recordedAt'], 11, 8)),
            );
        }

        return $this->page('Traces', '<div class="list">' . $rows . '</div>');
    }

    /**
     * @param array{
     *     meta: array{file: string, recordedAt: string, path: string, method: string, route: string, totalMs: float},
     *     spans: list<array{name: string, depth: int, startMs: float, durationMs: float|null, context: array<string, mixed>}>,
     *     marks: list<array{name: string, atMs: float, context: array<string, mixed>}>,
     *     queries: list<array{sql: string, durationMs: float, params: int}>
     * } $trace
     */
    public function renderTrace(array $trace): string
    {
        $meta = $trace['meta'];
        $total = max($meta['totalMs'], 0.001);

        $body = sprintf(
            '<div class="head">
               <a class="back" href="/__trace">&larr; all traces</a>
               <h1><span class="method big">%s</span> %s</h1>
               <div class="meta">%s &middot; %s ms &middot; %d queries &middot; %s</div>
             </div>',
            $this->e($meta['method']),
            $this->e($meta['path']),
            $this->e($meta['route'] !== '' ? $meta['route'] : 'no route name'),
            $this->ms($meta['totalMs']),
            count($trace['queries']),
            $this->e($meta['recordedAt']),
        );

        $body .= '<div class="waterfall">' . $this->spans($trace['spans'], $total) . '</div>';

        if ($trace['marks'] !== []) {
            $body .= $this->marks($trace['marks']);
        }

        if ($trace['queries'] !== []) {
            $body .= $this->queries($trace['queries'], $total);
        }

        return $this->page($meta['method'] . ' ' . $meta['path'], $body);
    }

    /**
     * @param list<array{name: string, depth: int, startMs: float, durationMs: float|null, context: array<string, mixed>}> $spans
     */
    private function spans(array $spans, float $total): string
    {
        $out = '';
        foreach ($spans as $s) {
            $dur = $s['durationMs'];
            $unclosed = $dur === null;
            $width = $unclosed ? 100.0 - $this->pct($s['startMs'], $total) : $this->pct($dur, $total);
            $share = $unclosed ? null : ($dur / $total) * 100;

            $detail = [];
            foreach ($s['context'] as $k => $v) {
                if ($k === 'marker' || $v === null || $v === '') {
                    continue;
                }
                $detail[] = $this->e($k) . '=' . '<b>' . $this->e($this->shortClass((string) $this->stringify($v))) . '</b>';
            }

            $out .= sprintf(
                '<div class="span%s" style="--indent:%dpx;--hue:%d">
                   <div class="label" title="%s">%s</div>
                   <div class="track">
                     <div class="bar" style="left:%s%%;width:%s%%"></div>
                   </div>
                   <div class="dur">%s</div>
                   <div class="share">%s</div>
                   <div class="ctx">%s</div>
                 </div>',
                $unclosed ? ' unclosed' : '',
                $s['depth'] * 14,
                $this->hue($s['name']),
                $this->e($s['name']),
                $this->e($s['name']),
                $this->num($this->pct($s['startMs'], $total)),
                $this->num(max($width, 0.15)),
                $unclosed ? '<span class="warn">never closed</span>' : $this->ms($dur) . ' ms',
                $share === null ? '' : $this->num($share) . '%',
                implode(' &middot; ', $detail),
            );
        }

        return $out;
    }

    /**
     * @param list<array{name: string, atMs: float, context: array<string, mixed>}> $marks
     */
    private function marks(array $marks): string
    {
        $items = '';
        foreach ($marks as $m) {
            $ctx = [];
            foreach ($m['context'] as $k => $v) {
                $ctx[] = $this->e($k) . '=' . $this->e((string) $this->stringify($v));
            }
            $items .= sprintf(
                '<li><span class="at">%s ms</span> <code>%s</code> <span class="dim">%s</span></li>',
                $this->ms($m['atMs']),
                $this->e($m['name']),
                implode(' ', $ctx),
            );
        }

        return '<section><h2>Decisions</h2><ul class="marks">' . $items . '</ul></section>';
    }

    /**
     * @param list<array{sql: string, durationMs: float, params: int}> $queries
     */
    private function queries(array $queries, float $total): string
    {
        // Repeats are what an N+1 looks like, so they are counted rather than
        // left for the reader to spot in a list of forty near-identical lines.
        $shapes = [];
        foreach ($queries as $q) {
            $key = preg_replace('/\s+/', ' ', trim($q['sql'])) ?? $q['sql'];
            $shapes[$key] = ($shapes[$key] ?? 0) + 1;
        }
        arsort($shapes);
        $worst = array_key_first($shapes);
        $repeated = $worst !== null && $shapes[$worst] > 1;

        $rows = '';
        foreach ($queries as $q) {
            $rows .= sprintf(
                '<div class="q"><div class="qbar" style="width:%s%%"></div><code>%s</code><span class="qdur">%s ms</span></div>',
                $this->num(max($this->pct($q['durationMs'], $total), 0.4)),
                $this->e($q['sql']),
                $this->ms($q['durationMs']),
            );
        }

        $warn = $repeated
            ? sprintf(
                '<p class="nplus">The same statement ran <b>%d times</b> — that shape is what an N+1 looks like.</p>',
                $shapes[$worst],
            )
            : '';

        return '<section><h2>Queries <span class="dim">' . count($queries) . '</span></h2>'
            . $warn . '<div class="queries">' . $rows . '</div></section>';
    }

    private function emptyState(string $dir): string
    {
        return '<div class="empty">
              <h1>No traces yet</h1>
              <p>Add <code>?__trace=1</code> to any request, or send the
                 <code>X-Semitexa-Trace: 1</code> header, then reload this page.</p>
              <p class="dim">Traces are written to <code>' . $this->e($dir) . '</code>
                 and only while <code>APP_ENV=dev</code>.</p>
            </div>';
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8">'
            . '<meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->e($title) . ' · Semitexa trace</title>'
            . '<style>' . $this->css() . '</style></head><body><main>'
            . $body . '</main></body></html>';
    }

    private function css(): string
    {
        return <<<'CSS'
*{box-sizing:border-box}
:root{
  --bg:#0b0e14;--panel:#111621;--line:#1e2532;--text:#e6e9ef;--dim:#8792a6;
  --accent:#5b9dff;--warn:#ffb454;--danger:#ff6b6b;
  --mono:ui-monospace,SFMono-Regular,"SF Mono",Menlo,monospace;
}
@media (prefers-color-scheme:light){
  :root{--bg:#f7f8fa;--panel:#fff;--line:#e4e7ec;--text:#141821;--dim:#667085}
}
body{margin:0;background:var(--bg);color:var(--text);
  font:14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,sans-serif}
main{max-width:1180px;margin:0 auto;padding:32px 24px 80px}
h1{font-size:20px;font-weight:600;margin:0 0 4px;letter-spacing:-.01em}
h2{font-size:13px;font-weight:600;text-transform:uppercase;letter-spacing:.08em;
  color:var(--dim);margin:36px 0 12px}
code{font-family:var(--mono);font-size:12.5px}
.dim{color:var(--dim);font-weight:400}
.back{color:var(--dim);text-decoration:none;font-size:13px}
.back:hover{color:var(--accent)}
.head{margin-bottom:28px}
.meta{color:var(--dim);font-size:13px}
.method{font-family:var(--mono);font-size:11px;font-weight:600;color:var(--accent);
  border:1px solid var(--line);border-radius:4px;padding:2px 6px}
.method.big{font-size:13px}

.list{border:1px solid var(--line);border-radius:10px;overflow:hidden;background:var(--panel)}
.row{display:grid;grid-template-columns:64px 1fr auto 92px 76px;gap:14px;align-items:center;
  padding:11px 16px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
.row:last-child{border-bottom:0}
.row:hover{background:color-mix(in srgb,var(--accent) 8%,transparent)}
.path{font-family:var(--mono);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.chip{font-size:11px;color:var(--dim)}
.num{font-family:var(--mono);text-align:right;font-variant-numeric:tabular-nums}
.when{font-family:var(--mono);font-size:11px;color:var(--dim);text-align:right}

.waterfall{border:1px solid var(--line);border-radius:10px;background:var(--panel);padding:6px 0}
.span{display:grid;
  grid-template-columns:minmax(150px,210px) 1fr 82px 54px;
  grid-template-areas:"label track dur share" "ctx ctx ctx ctx";
  gap:0 12px;align-items:center;padding:7px 16px;border-bottom:1px solid transparent}
.span:hover{background:color-mix(in srgb,var(--accent) 6%,transparent)}
.label{grid-area:label;padding-left:var(--indent);font-family:var(--mono);font-size:12.5px;
  overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.track{grid-area:track;position:relative;height:16px;border-radius:4px;
  background:color-mix(in srgb,var(--line) 55%,transparent)}
.bar{position:absolute;top:0;height:16px;border-radius:4px;
  background:linear-gradient(90deg,
    hsl(var(--hue) 70% 58%),
    hsl(calc(var(--hue) + 18) 70% 50%));
  box-shadow:0 0 0 1px hsl(var(--hue) 70% 40% / .35) inset}
.dur{grid-area:dur;font-family:var(--mono);font-size:12px;text-align:right;font-variant-numeric:tabular-nums}
.share{grid-area:share;font-family:var(--mono);font-size:11px;color:var(--dim);text-align:right}
.ctx{grid-area:ctx;font-size:11.5px;color:var(--dim);padding-left:calc(var(--indent) + 0px);
  margin-top:2px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.ctx b{color:var(--text);font-weight:500}
.span.unclosed .bar{background:repeating-linear-gradient(45deg,
  var(--danger),var(--danger) 6px,transparent 6px,transparent 12px)}
.warn{color:var(--warn)}

.marks{list-style:none;margin:0;padding:0;border:1px solid var(--line);
  border-radius:10px;background:var(--panel);overflow:hidden}
.marks li{display:flex;gap:12px;align-items:baseline;padding:9px 16px;border-bottom:1px solid var(--line)}
.marks li:last-child{border-bottom:0}
.at{font-family:var(--mono);font-size:11.5px;color:var(--dim);min-width:70px;text-align:right}

.queries{border:1px solid var(--line);border-radius:10px;background:var(--panel);overflow:hidden}
.q{position:relative;display:flex;gap:12px;align-items:center;padding:10px 16px;
  border-bottom:1px solid var(--line)}
.q:last-child{border-bottom:0}
.q code{flex:1;min-width:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;position:relative}
.qbar{position:absolute;left:0;top:0;bottom:0;background:hsl(95 60% 45% / .13)}
.qdur{font-family:var(--mono);font-size:12px;color:var(--dim);position:relative}
.nplus{background:color-mix(in srgb,var(--warn) 12%,transparent);
  border:1px solid color-mix(in srgb,var(--warn) 40%,transparent);
  border-radius:8px;padding:10px 14px;font-size:13px;margin:0 0 12px}

.empty{text-align:center;padding:80px 20px}
.empty h1{margin-bottom:12px}
.empty p{color:var(--dim);max-width:520px;margin:8px auto}
CSS;
    }

    private function hue(string $name): int
    {
        $prefix = explode('.', $name)[0];

        return self::HUES[$prefix] ?? 220;
    }

    private function pct(float $value, float $total): float
    {
        return max(0.0, min(100.0, ($value / $total) * 100));
    }

    private function shortClass(string $value): string
    {
        if (!str_contains($value, '\\')) {
            return $value;
        }

        $parts = explode('\\', $value);

        return (string) end($parts);
    }

    private function stringify(mixed $v): string
    {
        return match (true) {
            is_bool($v) => $v ? 'true' : 'false',
            is_scalar($v) => (string) $v,
            default => get_debug_type($v),
        };
    }

    private function ms(float $v): string
    {
        return number_format($v, $v < 10 ? 3 : 1, '.', '');
    }

    private function num(float $v): string
    {
        return number_format($v, 3, '.', '');
    }

    private function e(string $v): string
    {
        return htmlspecialchars($v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
