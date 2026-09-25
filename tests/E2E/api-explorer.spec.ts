import { test, expect, type APIRequestContext } from '@playwright/test';

/**
 * API Explorer (ep-dev-api-explorer): find a route by kind, call it for real
 * or in the sandbox, reach it from the Observatory, export OpenAPI.
 *
 * Routes are picked from the catalog at run time, never named: this spec
 * ships with semitexa/dev and must hold in any app it is installed into, not
 * only in the demo app it was written against.
 */

type Route = { id: string; kind: string; path: string; methods: string[]; access: string; path_params: string[] };

async function catalog(request: APIRequestContext): Promise<Route[]> {
    const res = await request.get('/__explorer/catalog');
    expect(res.ok()).toBeTruthy();
    return (await res.json()).routes as Route[];
}

/** A public GET route of this kind that needs no path parameter. */
async function simpleGet(request: APIRequestContext, kind: string): Promise<Route> {
    const route = (await catalog(request)).find(
        (r) => r.kind === kind && r.access === 'public' && r.methods.includes('GET') && r.path_params.length === 0,
    );
    expect(route, `the app must have a public parameterless GET ${kind} route`).toBeTruthy();
    return route!;
}

test.describe('API Explorer', () => {
    test('groups routes by kind and searches within a group', async ({ page, request }) => {
        const routes = await catalog(request);
        const pages = routes.filter((r) => r.kind === 'page').length;

        await page.goto('/__explorer');
        const pageGroup = page.locator('.ex-group[data-kind="page"]');
        await expect(pageGroup).toHaveAttribute('aria-pressed', 'true');
        await expect(pageGroup.locator('b')).toHaveText(String(pages));

        const target = routes.find((r) => r.kind === 'api')!;
        await page.locator('.ex-group[data-kind="api"]').click();
        await page.locator('#ex-q').fill(target.path);
        await expect(page.locator('.ex-item').first()).toHaveAttribute('data-id', target.id);
    });

    test('a real call reports status, the expectation and a working trace link', async ({ page, request }) => {
        const route = await simpleGet(request, 'api');
        await page.goto('/__explorer#kind=api&route=' + encodeURIComponent(route.id));

        await expect(page.locator('#ex-route-title')).toHaveText(route.path);
        await page.locator('.ex-send').click();

        const summary = page.locator('.ex-summary');
        await expect(summary.locator('.ex-pill')).toHaveText(/^\d{3} /);
        const trace = summary.locator('.ex-trace');
        await expect(trace).toBeVisible({ timeout: 5_000 });

        const href = await trace.getAttribute('href');
        expect(href).toMatch(/^\/__trace\?file=/);
        const traced = await request.get(href!);
        expect(traced.ok(), 'the trace the call produced must open').toBeTruthy();
    });

    test('a sandbox run rolls its writes back and says so', async ({ page, request }) => {
        const route = await simpleGet(request, 'api');
        await page.goto('/__explorer#kind=api&route=' + encodeURIComponent(route.id));

        await page.locator('.ex-mode [data-mode="sandbox"]').click();
        await expect(page.locator('.ex-note.sandbox')).toBeVisible();
        await page.locator('.ex-send').click();

        await expect(page.locator('.ex-summary .ex-pill').first()).toHaveText(/^SANDBOX · /, { timeout: 20_000 });
        await expect(page.locator('.ex-guards')).toContainText('transaction-rolled-back');
    });

    test('Ctrl+Enter while a call is in flight sends it once', async ({ page, request }) => {
        const route = await simpleGet(request, 'api');
        await page.goto('/__explorer#kind=api&route=' + encodeURIComponent(route.id));
        await expect(page.locator('.ex-send')).toBeVisible();

        // Hold the answer back, so every press lands while the first call is
        // still in flight — otherwise a fast app finishes between presses and
        // a second call is legitimate.
        let sent = 0;
        await page.route((url) => url.pathname === route.path, async (r) => {
            sent++;
            await new Promise((z) => setTimeout(z, 800));
            await r.continue();
        });
        await page.locator('.ex-method').focus();
        for (let i = 0; i < 4; i++) await page.keyboard.press('Control+Enter');
        await expect(page.locator('.ex-summary')).toBeVisible({ timeout: 5_000 });

        expect(sent).toBe(1);
    });

    test('opens from the Observatory, and hands its state to a new tab', async ({ page }) => {
        await page.goto('/__observatory');
        await page.locator('.obs [data-action="explorer"]').click();

        const dialog = page.locator('.obs .explorer-dialog');
        await expect(dialog).toBeVisible();
        const frame = page.frameLocator('.obs .explorer-dialog iframe');
        await frame.locator('.ex-group[data-kind="api"]').click();
        await frame.locator('#ex-q').fill('catalog');

        // The link is filled in on click; stop the navigation and read it.
        const detach = dialog.locator('[data-action="explorer-detach"]');
        await detach.evaluate((a) => a.addEventListener('click', (e) => e.preventDefault(), { once: true }));
        await detach.click();
        const href = new URL((await detach.getAttribute('href'))!, page.url());
        expect(href.pathname).toMatch(/\/__explorer$/);
        expect(new URLSearchParams(href.hash.slice(1)).get('q')).toBe('catalog');
    });

    test('boots under a strict CSP with nothing refused', async ({ page }) => {
        const violations: string[] = [];
        page.on('console', (m) => { if (m.type() === 'error') violations.push(m.text()); });
        await page.route('**/__explorer', async (route) => {
            const res = await route.fetch();
            await route.fulfill({
                response: res,
                headers: { ...res.headers(), 'content-security-policy': "default-src 'self'; script-src 'self'; style-src 'self'; connect-src 'self'; frame-src 'self'" },
            });
        });

        await page.goto('/__explorer');
        await expect(page.locator('.ex-group').first()).toBeVisible();
        expect(violations.filter((v) => /Refused|Applying inline style/i.test(v))).toEqual([]);
    });

    test('exports OpenAPI for every API route', async ({ request }) => {
        const api = (await catalog(request)).filter((r) => r.kind === 'api');
        const res = await request.get('/__explorer/openapi.json');
        expect(res.ok()).toBeTruthy();
        const doc = await res.json();

        expect(doc.openapi).toBe('3.1.0');
        const ids: string[] = [];
        for (const [path, operations] of Object.entries<Record<string, { operationId: string; parameters?: { in: string; name: string }[] }>>(doc.paths)) {
            const placeholders = [...path.matchAll(/\{([^}]+)\}/g)].map((m) => m[1]);
            for (const operation of Object.values(operations)) {
                ids.push(operation.operationId);
                const declared = (operation.parameters ?? []).filter((p) => p.in === 'path').map((p) => p.name);
                expect(declared.sort(), `${path} declares exactly its path params`).toEqual([...placeholders].sort());
            }
        }
        expect(new Set(ids).size, 'operationIds are unique').toBe(ids.length);
        expect(Object.keys(doc.paths).length).toBeGreaterThanOrEqual(new Set(api.map((r) => r.path.replace(/\{([A-Za-z_]\w*)[^}]*\}/g, '{$1}'))).size);
    });
});
