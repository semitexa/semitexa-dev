<?php

declare(strict_types=1);

namespace Semitexa\Dev\Application\Service\Explorer;

use Semitexa\Core\Attribute\AsService;

/**
 * The API Explorer shell at `/__explorer`.
 *
 * Same discipline as the Observatory panel: HTML from PHP because dev must not
 * depend on ssr, and no inline script, style block or `style=` attribute, so a
 * strict CSP cannot silently blank it. Everything dynamic is drawn by
 * `resources/explorer/explorer.js` from `/__explorer/catalog`.
 */
#[AsService]
final class ExplorerHtmlRenderer
{
    public function render(): string
    {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title>API Explorer · Semitexa</title>
<link rel="stylesheet" href="/__explorer/asset/explorer.css">
</head>
<body>
<div class="ex">
  <header class="ex-head">
    <h1>Semitexa <span>API Explorer</span></h1>
    <nav class="ex-links">
      <a href="/__observatory" target="_blank" rel="noopener">Observatory ↗</a>
      <a href="/__trace" target="_blank" rel="noopener">Traces ↗</a>
      <a href="/__explorer" target="_blank" rel="noopener" id="ex-detach" hidden>Open in new tab ↗</a>
    </nav>
  </header>
  <nav class="ex-groups" id="ex-groups" aria-label="Route kinds"></nav>
  <div class="ex-search">
    <input type="search" id="ex-q" placeholder="Search path, name, module, handler…   ( / )" autocomplete="off" spellcheck="false" aria-label="Search routes">
    <label class="ex-all"><input type="checkbox" id="ex-everywhere"> all groups</label>
  </div>
  <ul class="ex-list" id="ex-list" role="listbox" aria-label="Routes"></ul>
  <p class="ex-status" id="ex-status" role="status">Loading routes…</p>
</div>

<dialog class="ex-dialog" id="ex-route" aria-labelledby="ex-route-title">
  <header class="ex-dialog-head">
    <div class="ex-dialog-title"><span class="ex-methods" id="ex-route-methods"></span><h2 id="ex-route-title"></h2></div>
    <div class="ex-dialog-actions">
      <a id="ex-route-tab" target="_blank" rel="noopener" title="Open this route in a separate tab">New tab ↗</a>
      <button type="button" id="ex-route-close" aria-label="Close">✕</button>
    </div>
  </header>
  <div class="ex-dialog-body" id="ex-route-body"></div>
</dialog>
<script src="/__explorer/asset/explorer.js" defer></script>
<script src="/__explorer/asset/invoke.js" defer></script>
</body>
</html>
HTML;
    }

    public static function assetDir(): string
    {
        // src/Application/Service/Explorer → package root is four levels up.
        return dirname(__DIR__, 4) . '/resources/explorer';
    }
}
