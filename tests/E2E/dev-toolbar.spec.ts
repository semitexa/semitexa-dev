import { test, expect, type APIRequestContext } from '@playwright/test';

/**
 * The dev toolbar (ep-dev-api-explorer, phase 5). Under browser automation it
 * deliberately draws nothing — a fixed bar over the bottom edge would sit on
 * whatever other specs click there — so the specs that need the bar undo
 * `navigator.webdriver` first, and one spec pins that the default stays off.
 */

async function htmlPage(request: APIRequestContext): Promise<string> {
    const res = await request.get('/__explorer/catalog');
    const route = ((await res.json()).routes as { kind: string; access: string; methods: string[]; path_params: string[]; path: string }[])
        .find((r) => r.kind === 'page' && r.access === 'public' && r.methods.includes('GET') && r.path_params.length === 0);
    expect(route, 'the app must have a public parameterless page').toBeTruthy();
    return route!.path;
}

test.describe('Dev toolbar', () => {
    test('every page carries the placeholder; automation does not get the bar drawn', async ({ page, request }) => {
        await page.goto(await htmlPage(request));

        await expect(page.locator('#semitexa-devbar')).toHaveCount(1);
        await expect(page.locator('script[src="/__toolbar/asset/toolbar.js"]')).toHaveCount(1);
        await page.waitForLoadState('load');
        await expect(page.locator('[data-semitexa-devbar]')).toHaveCount(0);
    });

    test('the placeholder is the same on every load; the process id rides a header', async ({ request }) => {
        const path = await htmlPage(request);
        const placeholder = async () => {
            const res = await request.get(path, { headers: { Accept: 'text/html' } });
            const html = await res.text();
            return { tag: html.match(/<div id="semitexa-devbar"[^>]*>/)?.[0], timing: res.headers()['server-timing'] ?? '' };
        };
        const [a, b] = [await placeholder(), await placeholder()];

        expect(a.tag, 'the page carries the placeholder').toBeTruthy();
        expect(a.tag, 'nothing per-request in the HTML — a compression or cache check compares bodies').toBe(b.tag);
        expect(a.timing).toMatch(/sx-process;desc="p-\d+-[a-f0-9]+"/);
        expect(a.timing).not.toBe(b.timing);
    });

    test('the dev panels themselves get no toolbar', async ({ page }) => {
        await page.goto('/__observatory');
        await expect(page.locator('#semitexa-devbar')).toHaveCount(0);
    });

    test('draws status, time and route, and opens the Explorer on this route', async ({ page, request }) => {
        await page.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => false }));
        await page.goto(await htmlPage(request));

        const bar = page.locator('[data-semitexa-devbar] .bar');
        await expect(bar).toBeVisible();
        await expect(bar.locator('.pill')).toHaveText(/^\d{3}$/, { timeout: 5_000 });
        await expect(bar.locator('.cell b').first()).not.toHaveText('…');

        const routeId = await page.locator('#semitexa-devbar').getAttribute('data-route-id');
        await bar.locator('.act').click();
        const frame = page.frameLocator('[data-semitexa-devbar] dialog iframe');
        await expect(frame.locator('#ex-route-title')).toHaveText(routeId!.split(' ').slice(1).join(' '));
    });

    test('collapses to a pill and remembers it', async ({ page, request }) => {
        await page.addInitScript(() => Object.defineProperty(navigator, 'webdriver', { get: () => false }));
        const path = await htmlPage(request);
        await page.goto(path);

        const host = page.locator('[data-semitexa-devbar]');
        await host.locator('.bar .icon').click();
        await expect(host.locator('.mini')).toBeVisible();

        await page.goto(path);
        await expect(host.locator('.mini')).toBeVisible();
        await expect(host.locator('.bar')).toBeHidden();
        await host.locator('.mini').click();
        await expect(host.locator('.bar')).toBeVisible();
    });
});
