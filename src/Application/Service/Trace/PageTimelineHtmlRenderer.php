<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Trace;

use Semitexa\Core\Attribute\AsService;

/**
 * The page timeline as HTML for a developer: one row per event, in order,
 * with the time since the page's first event — which component sent what and
 * how long it took, which frames the stream wrote back, which feeds re-ran.
 */
#[AsService]
final class PageTimelineHtmlRenderer
{
    /** @param list<array{session: string, updatedAt: string, bytes: int}> $pages */
    public function renderPages(array $pages): string
    {
        if ($pages === []) {
            return $this->page('Page timelines', '<p class="empty">No page has been recorded yet. Open a page with a live component, click something, and come back.</p>');
        }
        $rows = '';
        foreach ($pages as $p) {
            $rows .= sprintf(
                '<a class="row" href="/__observatory/timeline?page=%s"><code>%s</code><span class="when">%s</span><span class="num">%s KB</span></a>',
                rawurlencode($p['session']),
                $this->e($p['session']),
                $this->e(substr($p['updatedAt'], 11, 8)),
                number_format($p['bytes'] / 1024, 1),
            );
        }

        return $this->page('Page timelines', '<div class="list">' . $rows . '</div>');
    }

    /** @param list<array<string, mixed>> $events */
    public function renderPage(string $session, array $events): string
    {
        if ($events === []) {
            return $this->page('Page ' . $session, '<p><a href="/__observatory/timeline">← all pages</a></p><p class="empty">Nothing recorded for this page.</p>');
        }
        $origin = (float) ($events[0]['t'] ?? 0);
        $counts = [];
        $rows = '';
        foreach ($events as $event) {
            $type = (string) ($event['type'] ?? '?');
            $counts[$type] = ($counts[$type] ?? 0) + 1;
            $rows .= sprintf(
                '<tr class="%s"><td class="num">+%s ms</td><td><span class="chip">%s</span></td><td>%s</td></tr>',
                $this->e($type),
                number_format((float) ($event['t'] ?? 0) - $origin, 1, '.', ''),
                $this->e($type),
                $this->describe($type, $event),
            );
        }
        $summary = implode(' · ', array_map(fn (string $t, int $n): string => $n . ' ' . $this->e($t), array_keys($counts), $counts));

        return $this->page('Page ' . $session, sprintf(
            '<p><a href="/__observatory/timeline">← all pages</a> · <code>%s</code> · %s</p><table><thead><tr><th>when</th><th>what</th><th>detail</th></tr></thead><tbody>%s</tbody></table>',
            $this->e($session),
            $summary,
            $rows,
        ));
    }

    /** @param array<string, mixed> $e */
    private function describe(string $type, array $e): string
    {
        $s = static fn (string $k): string => is_scalar($e[$k] ?? null) ? (string) $e[$k] : '';

        return match ($type) {
            'event' => sprintf(
                '<b>%s</b> %s.%s <span class="num">%s ms</span> → %s%s',
                $this->e($s('component')),
                $this->e($s('part')),
                $this->e($s('event')),
                $this->e($s('ms')),
                $this->e(implode(', ', array_map('strval', is_array($e['effects'] ?? null) ? $e['effects'] : [])) ?: 'no effect'),
                $s('failed') !== '' ? ' <span class="bad">failed: ' . $this->e($s('failed')) . '</span>' : '',
            ),
            'frame' => sprintf('%s %s<span class="num">%s B</span>%s', $this->e($s('event')), $s('id') !== '' ? '<code>' . $this->e($s('id')) . '</code> ' : '', $this->e($s('bytes')), $s('sub') !== '' ? ' <span class="dim">' . $this->e($s('sub')) . '</span>' : ''),
            'subscribe' => sprintf('<b>%s</b>%s <span class="dim">%s</span>', $this->e($s('feed')), ($e['patches'] ?? false) === true ? ' (keyed patches)' : '', $this->e($s('sub'))),
            'rerun' => sprintf('after a %s → sent %s <span class="dim">%s</span>', $this->e($s('cause')), $this->e($s('sent')), $this->e($s('sub'))),
            'replay' => sprintf('resumed after <code>%s</code>: %s frame(s) replayed', $this->e($s('from')), $this->e($s('frames'))),
            'reset' => sprintf('could not resume after <code>%s</code>: reset', $this->e($s('from'))),
            'deferred' => sprintf('<b>%s</b> rendered <span class="num">%s ms</span>', $this->e($s('component')), $this->e($s('ms'))),
            default => $this->e((string) json_encode($e)),
        };
    }

    private function page(string $title, string $body): string
    {
        return '<!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">'
            . '<title>' . $this->e($title) . ' · Observatory</title><style>' . self::CSS . '</style></head><body><main><h1>'
            . $this->e($title) . '</h1>' . $body . '</main></body></html>';
    }

    private function e(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private const CSS = <<<'CSS'
:root{color-scheme:light dark;font:14px/1.45 system-ui,sans-serif;--fg:light-dark(#1b1d22,#e8e9ee);--dim:light-dark(#5d6270,#9aa0ad);--line:light-dark(#e3e5ea,#2c2f37);--bg:light-dark(#fff,#15171c);--chip:light-dark(#eef0f6,#23262e);--bad:light-dark(#b42318,#ff8a80)}
body{margin:0;background:var(--bg);color:var(--fg)}main{max-width:72rem;margin:0 auto;padding:1.5rem 1rem}h1{font-size:1.25rem;margin:0 0 1rem}
a{color:inherit}code{font:12px ui-monospace,monospace}.list{display:grid;gap:.25rem}.row{display:flex;gap:1rem;align-items:center;padding:.5rem .75rem;border:1px solid var(--line);border-radius:6px;text-decoration:none}
.row code{flex:1}.when,.num,.dim{color:var(--dim);font-variant-numeric:tabular-nums}.empty{color:var(--dim)}table{width:100%;border-collapse:collapse}th,td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid var(--line);vertical-align:top}
th{font-weight:600;color:var(--dim)}td.num{white-space:nowrap;width:7rem}.chip{background:var(--chip);border-radius:4px;padding:0 .4rem;font-size:12px}.bad{color:var(--bad)}tr.event td{font-weight:500}
CSS;
}
