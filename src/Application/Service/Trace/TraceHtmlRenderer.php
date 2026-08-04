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

        $body .= $this->flow($trace['spans'], $trace['marks'], $total, $meta['file']);

        if ($trace['queries'] !== []) {
            $body .= $this->queries($trace['queries'], $total);
        }

        return $this->page($meta['method'] . ' ' . $meta['path'], $body);
    }

    /**
     * The flow: sequential steps stack downward, concurrent branches sit side by
     * side.
     *
     * Concurrency is read off coroutine ids, not off overlapping clocks. Spans
     * sharing a cid ran one after another; a span on a different cid whose parent
     * is the current one is a branch. Timings that merely overlap prove nothing —
     * two spans can overlap on the clock and still be the same coroutine
     * suspending, which is sequential.
     *
     * A spawn is recorded as a mark, not a span: it returns immediately and the
     * work happens elsewhere. So a branch attaches to the spawn point rather than
     * nesting inside an enclosing span.
     *
     * @param list<array<string, mixed>> $spans
     * @param list<array<string, mixed>> $marks
     */
    private function flow(array $spans, array $marks, float $total, string $from): string
    {
        $byCid = [];
        foreach ($spans as $s) {
            $byCid[$this->iv($s, 'cid')][] = ['kind' => 'span'] + $s;
        }
        foreach ($marks as $m) {
            $byCid[$this->iv($m, 'cid')][] = ['kind' => 'mark', 'startMs' => $this->fv($m, 'atMs'), 'durationMs' => null] + $m;
        }
        foreach ($byCid as &$items) {
            usort($items, fn (array $a, array $b): int => $this->fv($a, 'startMs') <=> $this->fv($b, 'startMs'));
        }
        unset($items);

        $rootCid = $spans !== [] ? $this->iv($spans[0], 'cid') : array_key_first($byCid);
        if ($rootCid === null) {
            return '<p class="dim">Nothing recorded.</p>';
        }

        $children = [];
        foreach ($byCid as $cid => $items) {
            $parent = $this->iv($items[0], 'pcid');
            if ($cid !== $rootCid) {
                $children[$parent][] = $cid;
            }
        }

        return '<div class="flow">' . $this->column($rootCid, $byCid, $children, $total, 0, $from) . '</div>';
    }

    /**
     * One coroutine as a vertical column, with any coroutines it spawned rendered
     * as columns beside it.
     *
     * @param array<int, list<array<string, mixed>>> $byCid
     * @param array<int, list<int>>                  $children
     */
    private function column(int $cid, array $byCid, array $children, float $total, int $depth, string $from): string
    {
        if ($depth > 6) {
            return '<div class="col"><div class="node dim">deeper branches omitted</div></div>';
        }

        $nodes = '';
        foreach ($byCid[$cid] ?? [] as $item) {
            $nodes .= $this->node($item, $total, $from);

            // A branch hangs off the spawn that created it, which is the point in
            // this column where the work left for another coroutine.
            if (str_contains($this->sv($item, 'name'), 'spawn')) {
                $kids = $children[$cid] ?? [];
                if ($kids !== []) {
                    $kid = array_shift($kids);
                    $children[$cid] = $kids;
                    $nodes .= '<div class="branch">'
                        . '<div class="branch-line" title="spawned coroutine ' . $kid . '"></div>'
                        . $this->column($kid, $byCid, $children, $total, $depth + 1, $from)
                        . '</div>';
                }
            }
        }

        return '<div class="col" data-cid="' . $cid . '"><div class="col-head">coroutine ' . $cid . '</div>' . $nodes . '</div>';
    }

    /** @param array<string, mixed> $a */
    private function sv(array $a, string $k): string
    {
        $v = $a[$k] ?? null;

        return is_string($v) ? $v : '';
    }

    /** @param array<string, mixed> $a */
    private function iv(array $a, string $k): int
    {
        $v = $a[$k] ?? null;

        return is_int($v) ? $v : 0;
    }

    /** @param array<string, mixed> $a */
    private function fv(array $a, string $k): float
    {
        $v = $a[$k] ?? null;

        return is_float($v) || is_int($v) ? (float) $v : 0.0;
    }

    /**
     * One step. Fixed height with the number beside it, plus a thin proportional
     * indicator — height cannot carry duration here, because a step taking 96% of
     * the request would be three screens tall and everything else an unreadable
     * sliver.
     *
     * @param array<string, mixed> $item
     */
    private function node(array $item, float $total, string $from): string
    {
        $name = $this->sv($item, 'name');
        $durRaw = $item['durationMs'] ?? null;
        $dur = is_float($durRaw) || is_int($durRaw) ? (float) $durRaw : null;
        $isMark = ($item['kind'] ?? '') === 'mark';
        $share = $dur !== null ? $this->pct($dur, $total) : 0.0;
        $context = (array) ($item['context'] ?? []);

        $detail = [];
        foreach ($context as $k => $v) {
            if ($k === 'marker' || $v === null || $v === '' || $v === false) {
                continue;
            }
            $detail[] = $k . ': ' . $this->shortClass((string) $this->stringify($v));
        }

        // Detail goes in the title, not on the page: six lines of small print under
        // every step is read once and never again.
        $tip = $detail === [] ? $name : $name . "\n" . implode("\n", $detail);

        // A step that names a class becomes a link into the project graph. The
        // trace says this class ran; the graph says what it is wired to, and that
        // half is a property of the code, so it is resolved on the way out rather
        // than frozen into the recording.
        $class = $this->classIn($context);
        $tag = $class === null ? 'div' : 'a';
        $href = $class === null ? '' : sprintf(
            ' href="/__trace/node?class=%s&amp;from=%s"',
            rawurlencode($class),
            rawurlencode($from),
        );

        if ($class !== null) {
            $tip .= "\n\nClick to open " . $this->shortClass($class) . ' in the project graph';
        }

        return sprintf(
            '<%s class="node%s%s" style="--hue:%d" title="%s"%s>
               <span class="nname">%s</span>
               <span class="nbar"><i style="width:%s%%"></i></span>
               <span class="ndur">%s</span>
             </%s>',
            $tag,
            $isMark ? ' mark' : '',
            $class === null ? '' : ' linked',
            $this->hue($name),
            $this->e($tip),
            $href,
            $this->e($name),
            $this->num($share),
            $dur === null ? ($isMark ? '' : '<span class="warn">open</span>') : $this->ms($dur) . ' ms',
            $tag,
        );
    }

    /**
     * The class a step points at, if it named one.
     *
     * Matched on shape rather than against a list of context keys, so a span
     * added later — a new gate, a new pipeline phase — is linkable the day it is
     * recorded instead of the day someone remembers to extend a list here.
     *
     * @param array<mixed, mixed> $context
     */
    private function classIn(array $context): ?string
    {
        $preferred = ['handler', 'payload', 'resource', 'gate'];

        foreach ($preferred as $key) {
            $value = $context[$key] ?? null;
            if (is_string($value) && $this->looksLikeClass($value)) {
                return $value;
            }
        }

        foreach ($context as $value) {
            if (is_string($value) && $this->looksLikeClass($value)) {
                return $value;
            }
        }

        return null;
    }

    private function looksLikeClass(string $value): bool
    {
        return str_contains($value, '\\')
            && preg_match('/^[A-Za-z_\x80-\xff][\w\x80-\xff]*(\\\\[A-Za-z_\x80-\xff][\w\x80-\xff]*)+$/', $value) === 1;
    }

    /**
     * One class as the graph knows it: what it is, and what it is wired to.
     *
     * The trace answers "what ran". This answers "why that, and what else touches
     * it" — the structural half, which lives in the graph and not in the
     * recording.
     *
     * @param array{
     *     fqcn: string, name: string, type: string, module: string,
     *     file: string, line: int,
     *     out: list<array{kind: string, fqcn: string, name: string, type: string}>,
     *     in: list<array{kind: string, fqcn: string, name: string, type: string}>
     * }|null $node
     */
    public function renderNode(string $requested, ?array $node, string $from, bool $graphAvailable): string
    {
        $back = $from !== ''
            ? '<a class="back" href="/__trace?file=' . rawurlencode($from) . '">&larr; back to the trace</a>'
            : '<a class="back" href="/__trace">&larr; all traces</a>';

        if ($node === null) {
            return $this->page('Not in the graph', $back . $this->missingNode($requested, $graphAvailable));
        }

        $head = sprintf(
            '<div class="head">%s
               <h1>%s <span class="kind">%s</span></h1>
               <div class="meta"><code>%s</code></div>
               <div class="meta dim">%s &middot; %s:%d</div>
             </div>',
            $back,
            $this->e($node['name']),
            $this->e($node['type']),
            $this->e($node['fqcn']),
            $this->e($node['module'] !== '' ? $node['module'] : 'no module'),
            $this->e($node['file']),
            $node['line'],
        );

        // Outgoing first: "what this reaches for" is the question a reader arrives
        // with, having just watched it run.
        return $this->page(
            $node['name'],
            $head
            . $this->edges('Reaches', $node['out'], $from, 'to')
            . $this->edges('Reached by', $node['in'], $from, 'from'),
        );
    }

    /**
     * @param list<array{kind: string, fqcn: string, name: string, type: string}> $edges
     */
    private function edges(string $title, array $edges, string $from, string $direction): string
    {
        if ($edges === []) {
            return '<h2>' . $this->e($title) . '</h2><p class="dim">No edges.</p>';
        }

        $rows = '';
        foreach ($edges as $edge) {
            $rows .= sprintf(
                '<a class="erow" href="/__trace/node?class=%s&amp;from=%s">
                   <span class="ekind">%s</span>
                   <span class="ename">%s</span>
                   <span class="etype">%s</span>
                 </a>',
                rawurlencode($edge['fqcn']),
                rawurlencode($from),
                $this->e(str_replace('_', ' ', $edge['kind'])),
                $this->e($edge['name']),
                $this->e($edge['type']),
            );
        }

        return '<h2>' . $this->e($title) . ' <span class="dim">' . count($edges) . '</span></h2>'
            . '<div class="edges" data-direction="' . $this->e($direction) . '">' . $rows . '</div>';
    }

    private function missingNode(string $requested, bool $graphAvailable): string
    {
        $what = $requested === ''
            ? '<p>No class was given.</p>'
            : '<p><code>' . $this->e($requested) . '</code> is not in the project graph.</p>';

        $why = $graphAvailable
            ? '<p class="dim">The graph is built but does not know this class — it may live outside the
                 scanned tree, or the graph may predate it. Rebuild with
                 <code>bin/semitexa ai:review-graph:generate</code>.</p>'
            : '<p class="dim">No project graph was found. Build one with
                 <code>bin/semitexa ai:review-graph:generate</code>, then reload.</p>';

        return '<div class="empty"><h1>Not in the graph</h1>' . $what . $why . '</div>';
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

.flow{display:flex;align-items:flex-start;gap:0;overflow-x:auto;padding:4px 0 12px}
.col{display:flex;flex-direction:column;gap:6px;min-width:270px;flex:0 0 auto;
  border:1px solid var(--line);border-radius:10px;background:var(--panel);padding:10px}
.col-head{font-family:var(--mono);font-size:10.5px;letter-spacing:.06em;
  text-transform:uppercase;color:var(--dim);margin-bottom:2px}
.node{display:grid;grid-template-columns:1fr 44px 74px;gap:10px;align-items:center;
  padding:7px 10px;border-radius:7px;cursor:default;
  background:color-mix(in srgb,hsl(var(--hue) 70% 55%) 12%,transparent);
  border-left:3px solid hsl(var(--hue) 70% 55%)}
.node:hover{background:color-mix(in srgb,hsl(var(--hue) 70% 55%) 22%,transparent)}
/* A step that names a class goes somewhere; one that does not must not pretend to. */
a.node{text-decoration:none;color:inherit;cursor:pointer}
a.node .nname{text-decoration:underline;text-decoration-color:color-mix(in srgb,currentColor 35%,transparent);
  text-underline-offset:3px}
a.node:hover .nname{text-decoration-color:currentColor}
.node.mark{background:transparent;border-left-style:dashed;opacity:.75}
.nname{font-family:var(--mono);font-size:12px;overflow:hidden;
  text-overflow:ellipsis;white-space:nowrap}
.nbar{height:3px;border-radius:2px;background:color-mix(in srgb,var(--line) 70%,transparent);
  overflow:hidden}
.nbar i{display:block;height:3px;background:hsl(var(--hue) 70% 55%)}
.ndur{font-family:var(--mono);font-size:11px;color:var(--dim);text-align:right;
  font-variant-numeric:tabular-nums}
/* A branch leaves the column sideways: that turn is the whole point of the shape. */
.branch{display:flex;align-items:flex-start;margin:2px 0 2px 10px}
.branch-line{width:26px;height:22px;margin-top:14px;
  border-left:2px solid var(--accent);border-bottom:2px solid var(--accent);
  border-bottom-left-radius:8px;opacity:.55;flex:0 0 auto}
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

.kind{font-family:var(--mono);font-size:11px;font-weight:500;color:var(--dim);
  border:1px solid var(--line);border-radius:4px;padding:2px 6px;vertical-align:middle}
.edges{border:1px solid var(--line);border-radius:10px;background:var(--panel);overflow:hidden}
.erow{display:grid;grid-template-columns:150px 1fr auto;gap:14px;align-items:baseline;
  padding:9px 16px;border-bottom:1px solid var(--line);text-decoration:none;color:inherit}
.erow:last-child{border-bottom:0}
.erow:hover{background:color-mix(in srgb,var(--accent) 8%,transparent)}
.ekind{font-family:var(--mono);font-size:11px;color:var(--accent)}
.ename{font-family:var(--mono);font-size:13px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.etype{font-size:11px;color:var(--dim)}

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
