import { test, expect } from '@playwright/test';

// Release browser smoke — Semitexa Demo ONLY (Site/OS/Platform are out of
// release smoke scope). Tracks the current Demo structure: home `/`, section
// routes `/demo/<section>`, feature routes `/demo/<section>/<slug>`. Markers
// mirror the curl route checks in release-auto-checks.sh (title/h1/CTA text
// stable across releases); the added value over curl is a real browser: the
// page must survive JS boot and DOM render, not just emit HTML.

const pages: ReadonlyArray<readonly [path: string, marker: string]> = [
  ['/', 'Get Started'],
  ['/demo/routing', 'Section Overview'],
  ['/demo/routing/basic', 'Basic Route'],
  ['/demo/di/readonly', 'Readonly Injection'],
  ['/demo/data/relations', 'Relations'],
  ['/demo/auth/session', 'Session Auth'],
  ['/demo/events/sync', 'Sync Events'],
  ['/demo/events/sse', 'SSE Stream'],
  ['/demo/rendering', 'One rendering story, not two'],
  ['/demo/rendering/components', 'Components'],
  ['/demo/rendering/seo', 'SEO'],
  ['/demo/rendering/deferred', 'Deferred Blocks'],
  ['/demo/platform/tenancy-resolution', 'Resolution Story'],
  ['/demo/api/graphql', 'GraphQL API'],
  ['/demo/cli/runtime-maintenance', 'Runtime Maintenance'],
  ['/demo/testing/payload-contracts', 'Payload Contract Testing'],
];

for (const [path, marker] of pages) {
  test(`Demo renders ${path}`, async ({ page }) => {
    // 'load', not 'networkidle': SSE pages hold a connection open forever.
    const response = await page.goto(path, { waitUntil: 'load' });
    expect(response?.status(), `HTTP status for ${path}`).toBe(200);
    const html = await page.content();
    expect(html, `marker "${marker}" on ${path}`).toContain(marker);
  });
}

test('Demo home boots without JS errors', async ({ page }) => {
  const errors: string[] = [];
  page.on('pageerror', (error) => errors.push(String(error)));
  await page.goto('/', { waitUntil: 'load' });
  await page.waitForTimeout(1000);
  expect(errors, 'uncaught JS errors on /').toEqual([]);
});
