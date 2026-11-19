import { test, expect, Page } from '@playwright/test';

/**
 * The Observatory's "UI flow" tab, in light and in dark, under the strict
 * policy the consumer serves: pick a module, see its screens as boxes, focus
 * one and read its links and forms with where they lead.
 *
 * Asserted against what the endpoint itself answers on this stack, so the
 * spec holds on any project: no module or screen is named here, and what a
 * project does not have (a form the graph can follow) is noted, not failed. A stack that
 * never built a graph must still get a page that says so.
 *
 * UI_FLOW_SHOTS=<dir> also saves a screenshot per theme, for a person to look
 * at. They show the project's own routes: keep them on this machine.
 */
const STRICT = "default-src 'self'; script-src 'self' 'nonce-abc123'; style-src 'self' 'nonce-abc123'; connect-src 'self'";

async function strict(page: Page): Promise<string[]> {
    const errors: string[] = [];
    page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text()); });
    page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
    await page.route('**/__observatory', async (route) => {
        const res = await route.fetch();
        await route.fulfill({ response: res, headers: { ...res.headers(), 'content-security-policy': STRICT } });
    });
    return errors;
}

/** WCAG contrast of two `rgb()` colours as the browser computed them. */
function contrast(a: string, b: string): number {
    const lum = (c: string) => {
        const [r, g, bl] = (c.match(/[\d.]+/g) ?? []).slice(0, 3).map((v) => {
            const s = Number(v) / 255;
            return s <= 0.03928 ? s / 12.92 : ((s + 0.055) / 1.055) ** 2.4;
        });
        return 0.2126 * r + 0.7152 * g + 0.0722 * bl;
    };
    const [hi, lo] = [lum(a), lum(b)].sort((x, y) => y - x);
    return (hi + 0.05) / (lo + 0.05);
}

for (const scheme of ['light', 'dark'] as const) {
    test(`the UI flow tab draws a module and focuses a screen (${scheme})`, async ({ page, request }) => {
        const overviewResponse = await request.get('/__observatory/graph?view=ui');
        const errors = await strict(page);
        await page.emulateMedia({ colorScheme: scheme });
        await page.setViewportSize({ width: 1600, height: 950 });

        await page.goto('/__observatory#graph');
        await expect(page.locator('#view-graph')).toBeVisible();

        if (overviewResponse.status() === 404) {
            expect((await overviewResponse.json()).error).toBe('no-graph');
            await expect(page.locator('.gv-side .gv-err')).toContainText('ai:review-graph:generate');
            expect(errors.filter((e) => /Refused|inline style|pageerror/i.test(e))).toEqual([]);
            return;
        }
        expect(overviewResponse.status()).toBe(200);
        const overview = await overviewResponse.json();
        expect(overview.status).toBe('ok');
        expect(overview.modules.length).toBeGreaterThan(0);

        await page.locator('.gv-tabs button', { hasText: 'UI flow' }).click();

        // The module list, and the layers: navigation and actions on, data and access named but not drawn.
        await expect(page.locator('.gv-find[data-module]')).toHaveCount(overview.modules.length);
        await expect(page.locator('.gv-ui-layers button.on')).toHaveText(['navigation', 'actions']);
        await expect(page.locator('.gv-ui-layers button:disabled')).toHaveText(['data', 'access']);

        // It opens on the module with the most screens; the canvas of the DAG gives way to it.
        const first = [...overview.modules].sort((a: any, b: any) => b.screens - a.screens || a.module.localeCompare(b.module))[0];
        const module = await (await request.get('/__observatory/graph?view=ui&module=' + encodeURIComponent(first.module))).json();
        await expect(page.locator('.gv-find[data-module].on')).toHaveAttribute('data-module', first.module);
        await expect(page.locator('.gv-main h2')).toContainText(`UI flow · ${first.module} · ${module.screens.length} screen`);
        await expect(page.locator('.gv-canvas-wrap')).toBeHidden();
        await expect(page.locator('.gv-ui-box:not(.gv-ui-ghost):not(.gv-ui-endpoint)')).toHaveCount(module.screens.length);
        await expect(page.locator('.gv-ui-box.gv-ui-ghost')).toHaveCount(module.ghosts.length);
        await expect(page.locator('.gv-ui-box.gv-ui-endpoint')).toHaveCount(module.endpoints.length);

        // Unresolved links are a count chip on their screen, never a wire.
        const withUnresolved = module.screens.filter((s: any) => s.unresolved > 0);
        await expect(page.locator('.gv-ui-chip.gv-warn')).toHaveCount(withUnresolved.length);
        if (withUnresolved.length) {
            await expect(page.locator(`.gv-ui-box[data-id="${withUnresolved[0].id.replace(/\\/g, '\\\\')}"] .gv-ui-chip.gv-warn`)).toHaveText('?' + withUnresolved[0].unresolved);
        }

        // A screen reads in this theme: its text against its box.
        const entry = page.locator(`.gv-ui-box[data-id="${module.entry.replace(/\\/g, '\\\\')}"]`);
        const colours = await entry.evaluate((box) => {
            const text = getComputedStyle(box.querySelector('.gv-ui-path') as Element).color;
            // The box's own background over the pane's: what the text is actually on.
            const probe = document.createElement('canvas'); probe.width = probe.height = 1;
            const ctx = probe.getContext('2d') as CanvasRenderingContext2D;
            for (let at: Element | null = box, stack: string[] = []; at; at = at.parentElement) {
                stack.unshift(getComputedStyle(at).backgroundColor);
                if (!at.parentElement) { for (const bg of stack) { ctx.fillStyle = bg; ctx.fillRect(0, 0, 1, 1); } }
            }
            const [r, g, b] = ctx.getImageData(0, 0, 1, 1).data;
            const [tr, tg, tb] = (() => { ctx.clearRect(0, 0, 1, 1); ctx.fillStyle = text; ctx.fillRect(0, 0, 1, 1); return ctx.getImageData(0, 0, 1, 1).data; })();
            return { text: `rgb(${tr}, ${tg}, ${tb})`, background: `rgb(${r}, ${g}, ${b})` };
        });
        expect(contrast(colours.text, colours.background), `${scheme}: ${colours.text} on ${colours.background}`).toBeGreaterThan(4.5);

        // Focus the entry: its elements, one hop out and one hop in, and its code.
        const screen = await (await request.get('/__observatory/graph?view=ui&id=' + encodeURIComponent(module.entry))).json();
        await entry.click();
        await expect(entry).toHaveClass(/sel/);
        await expect(page.locator('.gv-detail h3')).toContainText(screen.screen.path);
        await expect(page.locator('.gv-detail .gv-meta')).toContainText(screen.screen.at);
        await expect(page.locator('.gv-detail .gv-acts a').first()).toHaveAttribute('href', '/__trace/node?class=' + encodeURIComponent(screen.screen.fqcn));
        await expect(page.locator('.gv-detail .gv-ui-el')).toHaveCount(screen.elements.length);
        await expect(page.locator('.gv-detail .gv-sec h4', { hasText: 'Leads to' }).locator('span')).toHaveText(String(screen.out.length));
        await expect(page.locator('.gv-detail .gv-sec h4', { hasText: 'Reached from' }).locator('span')).toHaveText(String(screen.in.length));
        if (screen.elements.length) {
            await expect(page.locator('.gv-detail .gv-ui-el .gv-fe').first()).toContainText(screen.elements[0].at);
        }

        // Its wires are lit, the rest of the layout's among them.
        const touching = module.wires.filter((w: any) => w.from === module.entry || w.to === module.entry);
        await expect(page.locator('.gv-ui-wire.lit')).toHaveCount(touching.length);

        if (process.env.UI_FLOW_SHOTS) {
            await page.screenshot({ path: `${process.env.UI_FLOW_SHOTS}/ui-flow-${scheme}.png` });
        }

        // The layers, on a module that has forms: Demo, the largest here, has none, and a toggle
        // asserted where there is nothing to hide proves nothing.
        let withForms: any = null;
        for (const m of overview.modules) {
            const candidate = await (await request.get('/__observatory/graph?view=ui&module=' + encodeURIComponent(m.module))).json();
            if (candidate.wires.some((w: any) => w.layer === 'action')) { withForms = candidate; break; }
        }
        if (withForms === null) {
            // A project may have screens and no form the graph can follow: its UI is not wrong for that.
            test.info().annotations.push({ type: 'not-applicable', description: 'no module of this stack has a form wire: the action layer, endpoint and ghost checks were not run' });
        } else {
            await page.locator(`.gv-find[data-module="${withForms.module}"]`).click();
            await expect(page.locator('.gv-main h2')).toContainText(`UI flow · ${withForms.module} · `);
            // Opening another module clears the screen described beside the picture.
            await expect(page.locator('.gv-detail .gv-head')).toHaveCount(0);
            const drawn = (layers: string[]) => withForms.wires.filter((w: any) => layers.includes(w.layer));
            const actions = drawn(['action']);
            const stillReached = new Set(drawn(['navigation']).map((w: any) => w.to));
            const endpointsLeft = withForms.endpoints.filter((n: any) => stillReached.has(n.id)).length;
            expect(actions.length).toBeGreaterThan(0);
            await expect(page.locator('.gv-ui-wire')).toHaveCount(withForms.wires.length);
            await expect(page.locator('.gv-ui-wire.gv-ui-action')).toHaveCount(actions.length);
            await expect(page.locator('.gv-ui-box.gv-ui-endpoint:visible')).toHaveCount(withForms.endpoints.length);
            await page.locator('.gv-ui-layers button', { hasText: 'actions' }).click();
            await expect(page.locator('.gv-ui-wire.gv-ui-action')).toHaveCount(0);
            await expect(page.locator('.gv-ui-wire')).toHaveCount(withForms.wires.length - actions.length);
            // An endpoint a link still reaches stays; one only forms reached goes with them.
            await expect(page.locator('.gv-ui-box.gv-ui-endpoint:visible')).toHaveCount(endpointsLeft);
            await page.locator('.gv-ui-layers button', { hasText: 'actions' }).click();
            await expect(page.locator('.gv-ui-wire')).toHaveCount(withForms.wires.length);
            await expect(page.locator('.gv-ui-box.gv-ui-endpoint:visible')).toHaveCount(withForms.endpoints.length);
            // A ghost opens its module only when that module has a screen to show.
            for (const ghost of withForms.ghosts) {
                const box = page.locator(`.gv-ui-box.gv-ui-ghost[data-id="${ghost.id}"]`);
                if (ghost.screens > 0) await expect(box).toBeEnabled(); else await expect(box).toBeDisabled();
            }
        }

        // Leaving the tab gives the main pane back to the DAG.
        await page.locator('.gv-tabs button', { hasText: 'Entry points' }).click();
        await expect(page.locator('.gv-ui-stage')).toBeHidden();
        await expect(page.locator('.gv-canvas-wrap')).toBeVisible();

        expect(errors.filter((e) => /Refused|inline style|pageerror/i.test(e))).toEqual([]);
    });
}

/**
 * The main pane's title and the detail panel are shared with the other tabs.
 * An answer that arrives after the UI flow tab was left must write to
 * neither, and a screen asked for a moment ago must not answer over the
 * endpoint selected since (review of project-graph#39).
 */
const HANDLER = 'class:Semitexa\\Dev\\Application\\Handler\\PayloadHandler\\ObservatoryHandler';

async function slowly(page: Page, which: (url: URL) => boolean): Promise<void> {
    await page.route('**/__observatory/graph?*', async (route) => {
        const url = new URL(route.request().url());
        if (url.searchParams.get('view') === 'ui' && which(url)) await new Promise((r) => setTimeout(r, 900));
        await route.continue();
    });
}

test('a late answer does not write over another tab, or over an endpoint selected since', async ({ page, request }) => {
    const overviewResponse = await request.get('/__observatory/graph?view=ui');
    const errors = await strict(page);
    if (overviewResponse.status() === 404) {
        expect((await overviewResponse.json()).error).toBe('no-graph');
        return;
    }
    const overview = await overviewResponse.json();
    expect(overview.modules.length).toBeGreaterThan(0);

    // The overview is slow: the tab is left before it answers.
    await slowly(page, (url) => !url.searchParams.has('module') && !url.searchParams.has('id'));
    await page.goto('/__observatory#graph=' + encodeURIComponent(HANDLER));
    await expect(page.locator('.gv-detail h3')).toContainText('ObservatoryHandler');
    await expect(page.locator('.gv-main h2')).toContainText('Focus · ObservatoryHandler');
    await page.locator('.gv-tabs button', { hasText: 'UI flow' }).click();
    await page.locator('.gv-tabs button', { hasText: 'Entry points' }).click();
    // Long enough for the overview and the module it opens to have answered.
    await page.waitForResponse((r) => new URL(r.url()).searchParams.has('module'));
    await page.waitForTimeout(300);
    await expect(page.locator('.gv-main h2')).toContainText('Focus · ObservatoryHandler');
    await expect(page.locator('.gv-detail h3')).toContainText('ObservatoryHandler');
    // What arrived was kept: back on the tab, the module is drawn and the title is the tab's.
    await page.locator('.gv-tabs button', { hasText: 'UI flow' }).click();
    await expect(page.locator('.gv-main h2')).toContainText('UI flow · ');
    // A box, not a wire: a project's module may have one screen and no links (review, dev#281).
    await expect(page.locator('.gv-ui-box').first()).toBeVisible();
    await page.unroute('**/__observatory/graph?*');

    // A screen is slow: an endpoint is selected before it answers.
    let withEndpoint: any = null;
    for (const m of overview.modules) {
        const candidate = await (await request.get('/__observatory/graph?view=ui&module=' + encodeURIComponent(m.module))).json();
        if (candidate.endpoints.length) { withEndpoint = candidate; break; }
    }
    if (withEndpoint === null) {
        test.info().annotations.push({ type: 'not-applicable', description: 'no module of this stack has an endpoint: the endpoint selection check was not run' });
    } else {
        await slowly(page, (url) => url.searchParams.has('id'));
        await page.locator(`.gv-find[data-module="${withEndpoint.module}"]`).click();
        await expect(page.locator('.gv-main h2')).toContainText(`UI flow · ${withEndpoint.module} · `);
        const screen = page.locator(`.gv-ui-box[data-id="${withEndpoint.entry.replace(/\\/g, '\\\\')}"]`);
        const endpoint = page.locator('.gv-ui-box.gv-ui-endpoint').first();
        const answered = page.waitForResponse((r) => new URL(r.url()).searchParams.has('id'));
        await screen.click();
        await endpoint.click();
        await expect(page.locator('.gv-detail h3 .gv-type')).toHaveText('endpoint');
        await answered;
        await page.waitForTimeout(300);
        await expect(page.locator('.gv-detail h3 .gv-type')).toHaveText('endpoint');
        await expect(endpoint).toHaveClass(/sel/);
    }

    expect(errors.filter((e) => /Refused|inline style|pageerror/i.test(e))).toEqual([]);
});
