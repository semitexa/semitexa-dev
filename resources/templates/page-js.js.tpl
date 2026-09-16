/**
 * {{pageName}} — page script.
 *
 * A FILE, not a `<script>` in the template, and the difference is not style:
 *
 *   - A script inside markup that can arrive later (a deferred slot, a region
 *     that re-renders, a page swapped into a live document) is inert until it
 *     is re-created, and re-created it runs AGAIN — once per arrival. Every
 *     binding it makes has to be idempotent or it accumulates silently.
 *   - `DOMContentLoaded` has already fired for anything that arrives later, so
 *     code waiting for it simply never runs.
 *   - An inline script needs the response's CSP nonce. A file served from your
 *     own origin needs nothing.
 *
 * This module therefore binds on the ELEMENT it finds, marks what it has
 * bound, and runs correctly whether it is evaluated before, during or after
 * the markup exists.
 *
 * For an interaction with no server state — a dropdown, a modal, tabs, a
 * tooltip — prefer a UI behavior (`#[AsUiBehavior]` + `registerBehavior`). The
 * behavior runtime watches the document and connects late-arriving markup for
 * you, so none of the above is your problem.
 */

const ROOT = '.page-{{kebabName}}';
const BOUND = 'data-{{kebabName}}-bound';

function connect(root) {
    if (root.hasAttribute(BOUND)) return;
    root.setAttribute(BOUND, '');

    // Page behaviour goes here. `root` is the page container.
}

function connectAll() {
    document.querySelectorAll(ROOT).forEach(connect);
}

connectAll();

// Markup that arrives after this module ran — a deferred slot resolving, a
// region re-rendering — is connected by the same function. connect() is
// guarded, so a second pass over the same element does nothing.
new MutationObserver(connectAll).observe(document.documentElement, {
    childList: true,
    subtree: true,
});
