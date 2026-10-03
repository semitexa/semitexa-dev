import { test, expect, Page } from '@playwright/test';
import * as fs from 'fs';
import * as path from 'path';

/**
 * The Observatory's Evidence view, end to end, under the strict policy the
 * consumer serves: search, filter by kind and by day, sort both ways, page,
 * open a passport with its preview, and come back to the same state from the
 * address. 30 synthetic records are written straight into var/evidence/ (the
 * runner has no PHP) in the store's own passport format, and removed after.
 */
const STRICT = "default-src 'self'; script-src 'self' 'nonce-abc123'; style-src 'self' 'nonce-abc123'; connect-src 'self'";
const STORE = path.resolve(process.cwd(), 'var/evidence');
const COUNT = 30;
// A 1×1 PNG: the preview must decode it, not just request it.
const PNG = Buffer.from('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==', 'base64');
const ids: string[] = [];

function seed(): void {
    for (let i = 0; i < COUNT; i++) {
        const day = String(i + 1).padStart(2, '0'); // 2026-09-01 … 2026-09-30
        const id = `ev-202609${day}-120000-e2e0${i.toString(16).padStart(2, '0')}`;
        const png = i === 0;
        const kind = png ? 'screenshot' : (i % 2 === 0 ? 'log' : 'trace');
        const file = png ? 'e2e-ev-shot.png' : `e2e-ev-${day}.${kind === 'log' ? 'log' : 'json'}`;
        const body = png ? PNG : Buffer.from(`e2e evidence ${day}\n` + 'x'.repeat(i * 10));
        fs.mkdirSync(path.join(STORE, id), { recursive: true });
        fs.writeFileSync(path.join(STORE, id, file), body);
        fs.writeFileSync(path.join(STORE, id, 'meta.json'), JSON.stringify({
            schema: 'semitexa.evidence/v1', id, kind, data: 'synthetic', file, sha256: '', bytes: body.length,
            created_at: `2026-09-${day}T12:00:00+00:00`, expires_at: '2099-01-01T00:00:00+00:00',
            created_by: 'e2e', source: 'tests/E2E', note: `e2e-ev seeded ${day}`, visibility: 'private', publications: [],
        }));
        ids.push(id);
    }
}

test.beforeAll(seed);
test.afterAll(() => { for (const id of ids) fs.rmSync(path.join(STORE, id), { recursive: true, force: true }); });

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

const note = (page: Page) => page.locator('.ev-note span').first();
const files = (page: Page) => page.locator('.ev-table tbody tr td:nth-child(4)');

test('the evidence view searches, filters, sorts and pages under a strict CSP', async ({ page }) => {
    const errors = await strict(page);
    await page.goto('/__observatory#evidence?q=e2e-ev');
    await expect(page.locator('#view-evidence')).toBeVisible();
    await expect(page.locator('.obs')).toHaveClass(/mode-evidence/);
    await expect(note(page)).toContainText(`${COUNT} of`);

    // 25 a page by default: two pages, the second holds the rest.
    await expect(page.locator('.ev-of')).toHaveText('page 1 of 2');
    await expect(files(page)).toHaveCount(25);
    await page.locator('.ev-pager button', { hasText: /^2$/ }).click();
    await expect(page.locator('.ev-of')).toHaveText('page 2 of 2');
    await expect(files(page)).toHaveCount(5);
    // Newest first: page 2 ends on the oldest day.
    await expect(files(page).last()).toHaveText('e2e-ev-shot.png');

    // Sorting returns to page 1 and runs both ways.
    await page.locator('.ev-table th button', { hasText: 'Size' }).click();
    await expect(page.locator('th[aria-sort="descending"]')).toHaveText('Size');
    await expect(files(page).first()).toHaveText('e2e-ev-30.json');
    await page.locator('.ev-table th button', { hasText: 'Size' }).click();
    await expect(page.locator('th[aria-sort="ascending"]')).toHaveText('Size');
    // The smallest: 26 bytes of text, below the 67-byte PNG.
    await expect(files(page).first()).toHaveText('e2e-ev-02.json');

    // Kind: the menu counts what each choice would show.
    const kind = page.locator('.ev-bar select').first();
    await expect(kind.locator('option[value="log"]')).toHaveText('log (14)');
    await kind.selectOption('log');
    await expect(note(page)).toContainText('14 of');
    await kind.selectOption('');

    // A day range: both ends inclusive.
    await page.locator('.ev-bar input[type=date]').first().fill('2026-09-10');
    await page.locator('.ev-bar input[type=date]').nth(1).fill('2026-09-12');
    await expect(note(page)).toContainText('3 of');
    await expect(page).toHaveURL(/#evidence\?.*from=2026-09-10.*to=2026-09-12/);

    // The address is the state: a reload lands on the same table.
    await page.reload();
    await expect(note(page)).toContainText('3 of');
    await expect(page.locator('.ev-bar input[type=date]').first()).toHaveValue('2026-09-10');

    // Nothing matches: says so, and offers one page.
    await page.locator('.ev-bar input[type=search]').fill('e2e-ev no-such-thing');
    await expect(page.locator('.ev-empty')).toBeVisible();
    await expect(page.locator('.ev-of')).toHaveText('page 1 of 1');

    expect(errors).toEqual([]);
});

test('a row opens its passport and a preview of what it holds', async ({ page }) => {
    const errors = await strict(page);
    await page.goto('/__observatory#evidence?q=e2e-ev-12');
    await expect(files(page)).toHaveText(['e2e-ev-12.json']);
    await page.locator('.ev-table tbody tr').first().click();
    await expect(page.locator('.ev-side h3')).toHaveText('e2e-ev-12.json');
    await expect(page.locator('.ev-side pre')).toContainText('e2e evidence 12');
    await expect(page.locator('.ev-side dl')).toContainText('private: never approved for publication');
    await expect(page.locator('.ev-side')).toContainText('bin/semitexa ai:evidence publish ev-20260912-120000-e2e00b');

    await page.locator('.ev-bar input[type=search]').fill('e2e-ev-shot');
    await expect(page.locator('.ev-table tbody tr')).toHaveCount(1);
    await expect(files(page).first()).toHaveText('e2e-ev-shot.png');
    await page.locator('.ev-table tbody tr').first().click();
    const img = page.locator('.ev-side img');
    await expect(img).toBeVisible();
    await expect.poll(() => img.evaluate((i: HTMLImageElement) => i.naturalWidth)).toBe(1);

    expect(errors).toEqual([]);
});

test('leaving the evidence view hides it, and V brings it back as it was', async ({ page }) => {
    await page.goto('/__observatory#evidence?q=e2e-ev&kind=trace');
    await expect(note(page)).toContainText('15 of');
    await page.locator('#mode button[data-mode="live"]').click();
    await expect(page.locator('#view-evidence')).toBeHidden();
    await expect(page.locator('.river')).toBeVisible();
    await page.keyboard.press('v');
    await expect(page.locator('#view-evidence')).toBeVisible();
    await expect(page).toHaveURL(/#evidence\?.*kind=trace/);
    await page.keyboard.press('v');
    await expect(page.locator('#view-evidence')).toBeHidden();

    // A link with filters of its own, opened from live, wins over the state
    // the view held when it was left (it used to come back as kind=trace).
    await page.evaluate(() => { location.hash = '#evidence?q=e2e-ev&kind=log'; });
    await expect(page.locator('#view-evidence')).toBeVisible();
    await expect(note(page)).toContainText('14 of');
    await expect(page.locator('.ev-bar select').first()).toHaveValue('log');
});
