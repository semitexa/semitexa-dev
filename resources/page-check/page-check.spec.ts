import { test, expect, type Page } from '@playwright/test';
import { AxeBuilder } from '@axe-core/playwright';
import { createHash } from 'node:crypto';
import { mkdirSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

/**
 * Opens each page ai:verify named (PAGE_CHECK_PAGES, one path per line) in
 * light and dark, and files what it saw for PageCheckResult under
 * PAGE_CHECK_OUT/<sha256 of the path>.json. Run by bin/semitexa ai:verify in
 * the e2e-runner, against the app that is already running.
 *
 * axe alone is not enough, measured on 2026-11-07: on a page with no skin it
 * found nothing, so the checks below look for what a person sees and axe does
 * not (a skin never linked, a control with no edge, text against the window).
 * Light alone is not enough either: the invented token failed only in dark.
 */

const pages = (process.env.PAGE_CHECK_PAGES ?? '').split('\n').map((p) => p.trim()).filter((p) => p.startsWith('/'));
const out = process.env.PAGE_CHECK_OUT ?? '';
const AXE_RULES = ['color-contrast', 'label', 'button-name', 'link-name', 'document-title', 'html-has-lang'];

type View = { status: number; html: boolean; findings: string[]; stylesheets: string[]; error?: string; screenshot?: string };

async function look(page: Page): Promise<string[]> {
    return page.evaluate(() => {
        const found: string[] = [];
        // Only a page styled with the --ui tokens needs the skin that declares them.
        let usesTokens = false;
        for (const sheet of Array.from(document.styleSheets)) {
            try {
                for (const rule of Array.from(sheet.cssRules)) {
                    if (rule.cssText.includes('var(--ui-')) { usesTokens = true; break; }
                }
            } catch { /* a sheet from another origin cannot be read */ }
            if (usesTokens) break;
        }
        if (usesTokens && getComputedStyle(document.documentElement).getPropertyValue('--ui-surface-page').trim() === '') {
            found.push('no skin: the page uses --ui tokens and --ui-surface-page is not set');
        }
        const visible = (el: Element): boolean => {
            const s = getComputedStyle(el);
            const edge = (parseFloat(s.borderTopWidth) || 0) > 0 && s.borderTopStyle !== 'none' && s.borderTopColor !== 'rgba(0, 0, 0, 0)';
            const fill = s.backgroundColor !== 'rgba(0, 0, 0, 0)' && s.backgroundColor !== 'transparent';
            return edge || fill;
        };
        // A field with no edge and no fill is where a person cannot see to type; a button
        // drawn as a link is a design choice, and its text is what shows.
        for (const el of Array.from(document.querySelectorAll('input:not([type=hidden]):not([type=checkbox]):not([type=radio]):not([type=submit]):not([type=button]):not([type=file]),textarea,select'))) {
            if ((el as HTMLElement).offsetParent !== null && !visible(el)) {
                const name = el.getAttribute('name');
                found.push('invisible control: ' + el.tagName.toLowerCase() + (name ? '[' + name + ']' : ''));
            }
        }
        const text = document.querySelector('main')?.querySelector('h1,h2,p,form');
        if (text && text.getBoundingClientRect().left < 8) {
            found.push('text against the window edge');
        }
        return found;
    });
}

// Each page on its own: one page failing must not keep the others from being opened.
if (out !== '') mkdirSync(out, { recursive: true });

test('the page check has pages to open', () => {
    expect(out, 'PAGE_CHECK_OUT names where the results go').not.toBe('');
    expect(pages.length, 'PAGE_CHECK_PAGES names at least one page').toBeGreaterThan(0);
});

for (const path of pages) {
    test(`page ${path}`, async ({ browser }) => {
        const modes: Record<string, View> = {};
        const file = createHash('sha256').update(path).digest('hex');
        for (const mode of ['light', 'dark'] as const) {
            const context = await browser.newContext({ colorScheme: mode, viewport: { width: 1100, height: 800 } });
            const page = await context.newPage();
            const errors: string[] = [];
            page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 120)); });
            page.on('pageerror', (e) => errors.push(String(e).slice(0, 120)));
            try {
                // The response once the page has loaded; a page that keeps polling never goes idle,
                // and that is no reason to call it unreachable.
                const response = await page.goto(path, { waitUntil: 'load' });
                await page.waitForLoadState('networkidle', { timeout: 5_000 }).catch(() => undefined);
                const status = response?.status() ?? 0;
                const html = (response?.headers()['content-type'] ?? '').includes('text/html');
                const findings: string[] = [];
                if (html) {
                    const axe = await new AxeBuilder({ page }).withRules(AXE_RULES).analyze();
                    findings.push(...axe.violations.map((v) => `${v.id} x${v.nodes.length}`));
                    findings.push(...await look(page));
                    findings.push(...errors.slice(0, 3).map((c) => 'console: ' + c));
                }
                // Which stylesheets the page loaded: one that does not load the changed file cannot show the change.
                const stylesheets = html ? await page.evaluate(() => Array.from(document.querySelectorAll('link[rel~="stylesheet"]')).map((l) => (l as HTMLLinkElement).href)) : [];
                const screenshot = join(out, `${file}-${mode}.png`);
                if (html) await page.screenshot({ path: screenshot, fullPage: true });
                modes[mode] = { status, html, findings, stylesheets, screenshot: html ? screenshot : undefined };
            } catch (e) {
                modes[mode] = { status: 0, html: true, findings: [], stylesheets: [], error: String(e).split('\n')[0].slice(0, 200) };
            } finally {
                await context.close();
            }
        }
        writeFileSync(join(out, `${file}.json`), JSON.stringify({ path, modes }, null, 1));

        const all = Object.entries(modes).flatMap(([mode, v]) => (v.status >= 400 ? [`${mode}: HTTP ${v.status}`] : []).concat(v.findings.map((f) => `${mode}: ${f}`)));
        expect(all, `${path} as a person sees it`).toEqual([]);
    });
}
