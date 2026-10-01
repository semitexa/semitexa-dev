import { test, expect, Page } from '@playwright/test';

/**
 * The Observatory's Graph view, end to end, under the strict policy the
 * consumer serves (see observatory-csp.spec.ts for why that is the default
 * here): switch to it, walk a route down to its handler, find a class, focus
 * it in the DAG, follow a finding, and come in from a trace span.
 *
 * Needs a project graph. A stack that never ran `ai:review-graph:generate`
 * must still get a page that SAYS so — that branch is asserted instead.
 */
const HANDLER = 'class:Semitexa\\Dev\\Application\\Handler\\PayloadHandler\\ObservatoryHandler';
const PAYLOAD = 'class:Semitexa\\Dev\\Application\\Payload\\Request\\ObservatoryPayload';
const ROUTE = 'route:GET:/__observatory';

const STRICT = "default-src 'self'; script-src 'self' 'nonce-abc123'; style-src 'self' 'nonce-abc123'; connect-src 'self'";

async function strict(page: Page): Promise<string[]> {
    const errors: string[] = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    // The Observatory only: the trace viewer pages carry a style block of their
    // own and are not what this spec is about.
    await page.route('**/__observatory', async (route) => {
        const res = await route.fetch();
        await route.fulfill({ response: res, headers: { ...res.headers(), 'content-security-policy': STRICT } });
    });
    return errors;
}

const row = (page: Page, id: string) => page.locator(`.gv-row[data-id="${id.replace(/\\/g, '\\\\')}"]`).first();

test('the graph view walks, searches, focuses and follows findings under a strict CSP', async ({ page, request }) => {
    const summary = await request.get('/__observatory/graph?view=summary');
    const errors = await strict(page);

    await page.goto('/__observatory#graph');
    await expect(page.locator('#view-graph')).toBeVisible();
    await expect(page.locator('.obs')).toHaveClass(/mode-graph/);

    const noGraph = summary.status() === 404 && (await summary.json().catch(() => ({}))).error === 'no-graph';
    if (noGraph) {
        await expect(page.locator('.gv-side .gv-err')).toContainText('ai:review-graph:generate');
        expect(errors).toEqual([]);
        return;
    }
    expect(summary.status()).toBe(200);
    await expect(page.locator('.gv-stat')).toContainText('nodes');

    // Tree: a route expands to its payload, the payload to its handler.
    await page.locator('.gv-row.group[data-group="route"]').click();
    const route = row(page, ROUTE);
    await route.scrollIntoViewIfNeeded();
    await route.locator('.gv-caret').click();
    const payload = row(page, PAYLOAD);
    await expect(payload).toBeVisible();
    await payload.locator('.gv-caret').click();
    await expect(row(page, HANDLER)).toBeVisible();

    // Search reveals a class in the tree, selects it, and focuses the DAG on it.
    await page.locator('.gv-search input').fill('ObservatoryHandler');
    await expect(page.locator('.gv-hit').first()).toBeVisible();
    await page.locator('.gv-search input').press('Enter');
    await expect(page.locator('.gv-row.sel').first()).toHaveAttribute('data-id', HANDLER);
    await expect(page.locator('.gv-detail h3')).toContainText('ObservatoryHandler');
    await expect(page.locator('.gv-main h2')).toContainText(/Focus · ObservatoryHandler · \d+ nodes/);
    await expect(page).toHaveURL(/#graph=class%3A/);

    // The DAG drew something, at frame rate.
    const stats = await page.evaluate(() => {
        const canvas = document.querySelector('.gv-canvas-wrap canvas') as HTMLCanvasElement;
        return { w: canvas.width, h: canvas.height };
    });
    expect(stats.w).toBeGreaterThan(100);
    expect(stats.h).toBeGreaterThan(100);

    // Findings: a row focuses its node.
    await page.locator('.gv-tabs button', { hasText: 'Findings' }).click();
    await expect(page.locator('.gv-side .gv-gap')).toContainText(/Coverage/);
    const finding = page.locator('.gv-find').first();
    if (await finding.count()) {
        await finding.click();
        await expect(page.locator('.gv-detail h3')).not.toContainText('ObservatoryHandler');
    }

    // Nothing was refused: no script, no stylesheet, no inline style.
    expect(errors.filter((e) => /Refused|inline style|pageerror/i.test(e))).toEqual([]);
});

test('a trace node page opens its class in the graph', async ({ page, request }) => {
    const summary = await request.get('/__observatory/graph?view=summary');
    test.skip(summary.status() !== 200, 'no project graph on this stack');
    const errors = await strict(page);

    await page.goto('/__trace/node?class=' + encodeURIComponent(HANDLER.slice(6)));
    await page.getByRole('link', { name: /open in graph/ }).click();

    await expect(page.locator('.obs')).toHaveClass(/mode-graph/);
    await expect(page.locator('.gv-detail h3')).toContainText('ObservatoryHandler');
    await expect(page.locator('.gv-row.sel').first()).toHaveAttribute('data-id', HANDLER);
    expect(errors.filter((e) => /Refused|inline style|pageerror/i.test(e))).toEqual([]);
});

/**
 * The export, opened the way a reviewer opens a PR artifact: from disk.
 * Writing it needs PHP, which this runner does not have, so the file is made
 * beforehand (`bin/semitexa ai:review-graph:show --format=html --output=…`)
 * and handed over in GRAPH_EXPORT_FILE.
 */
test('the exported file works from file:// with no server', async ({ page }) => {
    const file = process.env.GRAPH_EXPORT_FILE;
    test.skip(!file, 'set GRAPH_EXPORT_FILE to an exported graph to run this');
    const errors: string[] = [];
    page.on('pageerror', (e) => errors.push(e.message));
    // The file enforces its own policy (default-src 'none' + a nonce): it must still run under it.
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    const requests: string[] = [];
    page.on('request', (r) => { if (!r.url().startsWith('file://') && !r.url().startsWith('data:')) requests.push(r.url()); });

    await page.goto('file://' + file);
    await expect(page.locator('.gv-stat')).toContainText('nodes');
    await page.locator('.gv-search input').fill('ObservatoryHandler');
    await expect(page.locator('.gv-hit').first()).toBeVisible();
    await page.locator('.gv-search input').press('Enter');
    await expect(page.locator('.gv-detail h3')).toContainText('ObservatoryHandler');
    await expect(page.locator('.gv-main h2')).toContainText(/Focus · ObservatoryHandler/);

    expect(errors).toEqual([]);
    expect(requests, 'the export fetches nothing').toEqual([]);
});
