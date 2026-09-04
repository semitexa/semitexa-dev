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
     *     meta: array{file: string, recordedAt: string, path: string, method: string, route: string, totalMs: float, truncated?: bool},
     *     spans: list<array{name: string, depth: int, startMs: float, durationMs: float|null, context: array<string, mixed>}>,
     *     marks: list<array{name: string, atMs: float, context: array<string, mixed>}>,
     *     queries: list<array{sql: string, durationMs: float, params: int, atMs?: float|null}>
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

        // Said before the flow, not after: a reader who scrolls a capped trace
        // without knowing it is capped will conclude the request ended where the
        // events stop.
        if (($meta['truncated'] ?? false) === true) {
            $body .= '<p class="nplus">This trace hit the ' . TraceBuffer::MAX_EVENTS
                . '-event cap. Everything after that point is missing, and the total
                   below is elapsed time rather than the closing span.</p>';
        }

        $body .= $this->waterfall($trace['spans'], $trace['marks'], $trace['queries'], $total, $meta['file']);

        if ($trace['queries'] !== []) {
            $body .= $this->queries($trace['queries'], $total);
        }

        return $this->page($meta['method'] . ' ' . $meta['path'], $body);
    }

    /**
     * The waterfall: time is the horizontal axis, and every step sits where it
     * happened.
     *
     * Rows are spans and marks in start order, indented by depth; the bar on each
     * row starts at the step's offset into the request and is as wide as its
     * duration, so "what ran while what" is read off alignment rather than
     * inferred from numbers. Linear on purpose — a 0.01 ms gate next to a 200 ms
     * handler SHOULD look like nothing, because it was nothing; that is the whole
     * point of drawing it. A bar keeps a 2px floor so a measured step never
     * disappears and gets mistaken for missing.
     *
     * Concurrency is read off coroutine ids, not off overlapping clocks: a row
     * that ran on a coroutine other than the root carries a chip saying so.
     * Queries get one lane of ticks rather than a row each — a request can run
     * hundreds, and the shape of that lane (a comb) is the N+1 signature.
     *
     * @param list<array<string, mixed>> $spans
     * @param list<array<string, mixed>> $marks
     * @param list<array<string, mixed>> $queries
     */
    private function waterfall(array $spans, array $marks, array $queries, float $total, string $from): string
    {
        if ($spans === [] && $marks === []) {
            return '<p class="dim">Nothing recorded.</p>';
        }

        $items = [];
        foreach ($spans as $s) {
            $items[] = ['kind' => 'span'] + $s;
        }
        foreach ($marks as $m) {
            $items[] = ['kind' => 'mark', 'startMs' => $this->fv($m, 'atMs'), 'durationMs' => null] + $m;
        }
        // Start order, and on a tie the shallower first: a span begins before
        // anything it encloses.
        usort($items, fn (array $a, array $b): int => [$this->fv($a, 'startMs'), $this->iv($a, 'depth')] <=> [$this->fv($b, 'startMs'), $this->iv($b, 'depth')]);

        $rootCid = $spans !== [] ? $this->iv($spans[0], 'cid') : $this->iv($items[0], 'cid');

        $rows = '';
        foreach ($items as $item) {
            $rows .= $this->node($item, $total, $from, $rootCid);
        }

        return '<div class="wf">' . $this->ruler($total) . $rows . $this->queryLane($queries, $total) . '</div>';
    }

    /** Tick marks with the elapsed time at each quarter, so a bar can be read in ms without hovering. */
    private function ruler(float $total): string
    {
        $ticks = '';
        foreach ([0, 25, 50, 75, 100] as $pct) {
            $ticks .= sprintf(
                '<b style="left:%d%%"><span>%s</span></b>',
                $pct,
                $pct === 0 ? '0' : $this->ms($total * $pct / 100) . ' ms',
            );
        }

        return '<div class="wf-ruler"><span class="nname dim">step</span><span class="wf-time">' . $ticks . '</span><span class="ndur dim">took</span></div>';
    }

    /**
     * @param list<array<string, mixed>> $queries
     */
    private function queryLane(array $queries, float $total): string
    {
        if ($queries === []) {
            return '';
        }

        $ticks = '';
        $placed = 0;
        $sum = 0.0;
        foreach ($queries as $q) {
            $sum += $this->fv($q, 'durationMs');
            $at = $q['atMs'] ?? null;
            if (!is_float($at) && !is_int($at)) {
                continue;
            }
            $placed++;
            $ticks .= sprintf(
                '<i style="left:%s%%;width:%s%%" title="%s"></i>',
                $this->num($this->pct((float) $at, $total)),
                $this->num($this->pct($this->fv($q, 'durationMs'), $total)),
                $this->e($this->ms($this->fv($q, 'durationMs')) . ' ms @ ' . $this->ms((float) $at) . ' ms'),
            );
        }

        $label = count($queries) . ' quer' . (count($queries) === 1 ? 'y' : 'ies');
        if ($placed < count($queries)) {
            // Older traces recorded queries without a position; say so instead of
            // drawing an empty lane that reads as "no queries ran".
            $label .= $placed === 0 ? ' (no positions recorded)' : ' (' . $placed . ' placed)';
        }

        return sprintf(
            '<div class="node qlane" style="--hue:95" title="%s">
               <span class="nname">%s</span>
               <span class="wf-bar">%s</span>
               <span class="ndur">%s ms</span>
             </div>',
            $this->e('ORM queries on the timeline; a comb of identical ticks is what an N+1 looks like'),
            $this->e($label),
            $ticks,
            $this->ms($sum),
        );
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
     * One row of the waterfall: the name (indented by depth), the bar placed at
     * the step's offset and sized by its duration, and the number.
     *
     * A mark has no duration and draws as a tick. An open span — one the request
     * died inside — runs from its start to the end of the trace and says so.
     *
     * @param array<string, mixed> $item
     */
    private function node(array $item, float $total, string $from, int $rootCid = 0): string
    {
        $name = $this->sv($item, 'name');
        $durRaw = $item['durationMs'] ?? null;
        $dur = is_float($durRaw) || is_int($durRaw) ? (float) $durRaw : null;
        $isMark = ($item['kind'] ?? '') === 'mark';
        $start = $this->pct($this->fv($item, 'startMs'), $total);
        $isOpen = !$isMark && $dur === null;
        $share = $isOpen ? max(0.0, 100.0 - $start) : ($dur !== null ? $this->pct($dur, $total) : 0.0);
        $depth = max(0, $this->iv($item, 'depth'));
        $cid = $this->iv($item, 'cid');
        $context = (array) ($item['context'] ?? []);

        $detail = ['at ' . $this->ms($this->fv($item, 'startMs')) . ' ms'
            . ($dur !== null ? ', took ' . $this->ms($dur) . ' ms' : ($isOpen ? ', never closed' : ''))];
        if ($cid !== $rootCid) {
            $detail[] = 'coroutine ' . $cid . ' (spawned by ' . $this->iv($item, 'pcid') . ')';
        }
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
        // A span that names its method (handler => X, method => handle) links
        // straight to that method's source; one that names only the class
        // lands on the class's conventional entry point.
        $target = SpanTarget::of($context);
        $class = $target?->class;
        $method = $target?->method;
        $tag = $class === null ? 'div' : 'a';
        $href = $class === null ? '' : sprintf(
            ' href="/__trace/node?class=%s&amp;from=%s%s"',
            rawurlencode($class),
            rawurlencode($from),
            $method === null ? '' : '&amp;method=' . rawurlencode($method),
        );

        if ($class !== null) {
            $tip .= "\n\nClick to open " . $this->shortClass($class)
                . ($method === null ? '' : '::' . $method . '()')
                . ' — source and project graph';
        }

        $bar = $isMark
            ? sprintf('<b class="wf-tick" style="left:%s%%"></b>', $this->num($start))
            : sprintf('<i%s style="left:%s%%;width:%s%%"></i>', $isOpen ? ' class="open"' : '', $this->num($start), $this->num($share));

        return sprintf(
            '<%s class="node%s%s%s" style="--hue:%d;--depth:%d" title="%s"%s>
               <span class="nname">%s%s</span>
               <span class="wf-bar">%s</span>
               <span class="ndur">%s</span>
             </%s>',
            $tag,
            $isMark ? ' mark' : '',
            $class === null ? '' : ' linked',
            $isOpen ? ' unfinished' : '',
            $this->hue($name),
            $depth,
            $this->e($tip),
            $href,
            $cid !== $rootCid ? '<em class="cid" title="coroutine ' . $cid . '">c' . $cid . '</em>' : '',
            // Eight rows named pipeline.listener say nothing until each says WHICH
            // listener; the class rides on the row, not only in the tooltip.
            $this->e($name) . ($class === null ? '' : ' <span class="who">' . $this->e($this->shortClass($class)) . '</span>'),
            $bar,
            $dur === null ? ($isMark ? '' : '<span class="warn">open</span>') : $this->ms($dur) . ' ms',
            $tag,
        );
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
     *     file: string, line: int, endLine?: int,
     *     out: list<array{kind: string, fqcn: string, name: string, type: string}>,
     *     in: list<array{kind: string, fqcn: string, name: string, type: string}>
     * }|null $node
     * @param string $method the method the reader arrived with (from the trace
     *                       link), carried through the scope toggle so the way
     *                       back lands on that method and not on a guess
     */
    public function renderNode(
        string $requested,
        ?array $node,
        string $from,
        bool $graphAvailable,
        ?SourceSlice $slice = null,
        bool $classScope = false,
        string $method = '',
    ): string {
        $back = $from !== ''
            ? '<a class="back" href="/__trace?file=' . rawurlencode($from) . '">&larr; back to the trace</a>'
            : '<a class="back" href="/__trace">&larr; all traces</a>';

        if ($node === null && $slice === null) {
            return $this->page('Not in the graph', $back . $this->missingNode($requested, $graphAvailable));
        }

        // The graph may not know a class whose file is right there on disk (a
        // class newer than the last graph build, say). The source is then the
        // whole page, and the head is built from what the slice knows.
        $fqcn = $node['fqcn'] ?? $slice?->fqcn ?? $requested;
        $head = sprintf(
            '<div class="head">%s
               <h1>%s <span class="kind">%s</span></h1>
               <div class="meta"><code>%s</code></div>
               <div class="meta dim">%s &middot; %s:%d</div>
             </div>',
            $back,
            $this->e($this->shortClass($fqcn)),
            $this->e($node['type'] ?? 'not in graph'),
            $this->e($fqcn),
            $this->e(($node['module'] ?? '') !== '' ? $node['module'] : 'no module'),
            $this->e($node['file'] ?? $slice?->file ?? ''),
            $node['line'] ?? $slice?->startLine ?? 0,
        );

        // Source first: a reader who clicked a step wants to see what ran before
        // they want to see what it is wired to. Then outgoing edges — "what this
        // reaches for" — then incoming.
        $body = $head . $this->source($fqcn, $slice, $from, $classScope, $method);
        if ($node !== null) {
            $body .= $this->edges('Reaches', $node['out'], $from, 'to')
                . $this->edges('Reached by', $node['in'], $from, 'from');
        } else {
            $body .= '<section><h2>Graph</h2><p class="dim">'
                . ($graphAvailable
                    ? 'The project graph does not know this class yet — rebuild with <code>bin/semitexa ai:review-graph:generate</code> to see what it is wired to.'
                    : 'No project graph was found. Build one with <code>bin/semitexa ai:review-graph:generate</code> to see what this class is wired to.')
                . '</p></section>';
        }

        return $this->page($this->shortClass($fqcn), $body);
    }

    /**
     * The code behind the class: one method by default, the class on request.
     *
     * Line numbers are the file's own, so a reader can go straight to the
     * editor; the toggle swaps scope without losing the trace they came from.
     */
    private function source(string $fqcn, ?SourceSlice $slice, string $from, bool $classScope, string $method): string
    {
        if ($slice === null) {
            return '<section><h2>Source</h2><p class="dim">Source unavailable — the class could not be '
                . 'loaded, or its file is outside the project root.</p></section>';
        }

        // The recorded method rides along: in class scope the slice has none,
        // and without it "entry method" would re-resolve by convention.
        $carried = $method !== '' ? $method : ($slice->method ?? '');
        $link = fn (string $scope): string => sprintf(
            '/__trace/node?class=%s&amp;from=%s&amp;scope=%s%s',
            rawurlencode($fqcn),
            rawurlencode($from),
            $scope,
            $carried === '' ? '' : '&amp;method=' . rawurlencode($carried),
        );

        $what = $slice->method !== null
            ? '<code>' . $this->e($slice->method) . '()</code>'
            : 'whole class';

        $toggle = match (true) {
            $classScope => '<a class="src-toggle" href="' . $link('method') . '">entry method</a>',
            $slice->method !== null => '<a class="src-toggle" href="' . $link('class') . '">whole class</a>',
            // Method scope was asked for and none was found: the class is
            // already what is shown, and a toggle to it would be a no-op.
            default => '<span class="dim">no conventional entry method — showing the class</span>',
        };

        $notes = '';
        if ($slice->truncated) {
            $notes .= '<p class="nplus">Cut at ' . count($slice->lines) . ' lines. The rest is in the file — '
                . '<code>' . $this->e($slice->file) . '</code>.</p>';
        }
        if ($slice->origin === 'graph') {
            $notes .= '<p class="dim src-note">Bounds from the project graph, which is as fresh as its last build; '
                . 'the class itself could not be loaded, so only the class view is available.</p>';
        }

        $numbered = '';
        foreach ((new SourceHighlighter())->lines($slice->lines) as $html) {
            $numbered .= '<li>' . ($html === '' ? '&nbsp;' : $html) . '</li>';
        }

        return sprintf(
            '<section>
               <h2>Source <span class="dim src-where">%s &middot; %s:%d–%d</span></h2>
               <div class="src-head">%s%s</div>%s
               <pre class="src"><ol start="%d">%s</ol></pre>
             </section>',
            $what,
            $this->e($slice->file),
            $slice->startLine,
            $slice->endLine,
            $toggle,
            $slice->method !== null
                ? ' <span class="dim">&middot; ' . $this->e($this->shortClass($slice->fqcn)) . '::' . $this->e($slice->method) . '</span>'
                : '',
            $notes,
            $slice->startLine,
            $numbered,
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

/* Waterfall: name | timeline | number. The timeline column is the request, 0 to total. */
.wf{border:1px solid var(--line);border-radius:10px;background:var(--panel);overflow:hidden}
.wf-ruler,.node{display:grid;grid-template-columns:minmax(220px,34%) 1fr 84px;gap:12px;align-items:center;
  padding:0 12px;border-bottom:1px solid var(--line)}
.wf-ruler{height:30px;color:var(--dim);font-family:var(--mono);font-size:10.5px;letter-spacing:.06em;text-transform:uppercase}
.wf-time{position:relative;height:100%}
.wf-time b{position:absolute;top:0;bottom:0;border-left:1px solid var(--line);font-weight:400}
.wf-time b span{position:absolute;top:8px;left:4px;white-space:nowrap;text-transform:none;letter-spacing:0}
.wf-time b:last-child span{left:auto;right:4px}
.node{height:30px;cursor:default;border-left:3px solid hsl(var(--hue) 70% 55%)}
.node:last-child{border-bottom:0}
.node:hover{background:color-mix(in srgb,hsl(var(--hue) 70% 55%) 12%,transparent)}
/* A step that names a class goes somewhere; one that does not must not pretend to. */
a.node{text-decoration:none;color:inherit;cursor:pointer}
a.node .nname{text-decoration:underline;text-decoration-color:color-mix(in srgb,currentColor 35%,transparent);
  text-underline-offset:3px}
a.node:hover .nname{text-decoration-color:currentColor}
.node.mark{border-left-style:dashed;opacity:.8}
.node.mark .nname{color:var(--dim)}
.node.qlane{border-left-color:hsl(95 60% 45%)}
.nname{font-family:var(--mono);font-size:12px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;
  padding-left:calc(var(--depth,0) * 14px)}
.who{color:var(--dim);font-size:11px}
a.node:hover .who{color:var(--text)}
.cid{font-style:normal;font-size:10px;color:var(--accent);border:1px solid color-mix(in srgb,var(--accent) 40%,transparent);
  border-radius:3px;padding:0 4px;margin-right:6px;vertical-align:middle}
.wf-bar{position:relative;height:12px;border-radius:2px;
  background:repeating-linear-gradient(90deg,transparent 0 24.9%,color-mix(in srgb,var(--line) 70%,transparent) 24.9% 25%)}
.wf-bar i{position:absolute;top:0;bottom:0;min-width:2px;border-radius:2px;background:hsl(var(--hue) 70% 55%)}
.wf-bar i.open{background:repeating-linear-gradient(90deg,var(--warn) 0 6px,transparent 6px 10px);opacity:.8}
.wf-bar .wf-tick{position:absolute;top:-3px;width:2px;height:18px;background:hsl(var(--hue) 70% 55%);opacity:.9}
.qlane .wf-bar i{background:hsl(95 60% 45%);min-width:1px}
.ndur{font-family:var(--mono);font-size:11px;color:var(--dim);text-align:right;
  font-variant-numeric:tabular-nums}
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

/* Source: the file's own numbering, the page's own palette. */
.src-where{text-transform:none;letter-spacing:0;font-family:var(--mono);font-size:12px}
.src-head{display:flex;gap:10px;align-items:baseline;font-size:13px;margin:0 0 10px}
.src-toggle{color:var(--accent);text-decoration:none;font-size:12px;border:1px solid var(--line);
  border-radius:4px;padding:2px 8px}
.src-toggle:hover{background:color-mix(in srgb,var(--accent) 10%,transparent)}
.src-note{font-size:12.5px;margin:0 0 10px}
.src{margin:0;border:1px solid var(--line);border-radius:10px;background:var(--panel);
  overflow-x:auto;font-family:var(--mono);font-size:12.5px;line-height:1.55;tab-size:4}
.src ol{margin:0;padding:12px 0 12px 64px;list-style:decimal}
.src li{padding:0 16px 0 8px;white-space:pre}
.src li::marker{color:var(--dim);font-size:11px;font-variant-numeric:tabular-nums}
.src li:hover{background:color-mix(in srgb,var(--accent) 6%,transparent)}
.src .k{color:#c678dd}.src .s{color:#98c379}.src .c{color:var(--dim);font-style:italic}
.src .v{color:#e5c07b}.src .n{color:#d19a66}.src .i{color:var(--text)}.src .a{color:#56b6c2}
@media (prefers-color-scheme:light){
  .src .k{color:#a626a4}.src .s{color:#50a14f}.src .v{color:#986801}.src .n{color:#c18401}.src .a{color:#0184bc}
}

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
