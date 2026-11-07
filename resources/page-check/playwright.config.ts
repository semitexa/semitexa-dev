import { defineConfig, devices } from '@playwright/test';

/**
 * The page check ai:verify runs on the host (page-check.spec.ts), apart from
 * the project's own E2E config: its spec lives in this package, which no
 * project's testMatch covers, and it must not pick up the project's specs.
 */
export default defineConfig({
    testDir: '.',
    testMatch: ['page-check.spec.ts'],
    timeout: 60_000,
    fullyParallel: false,
    workers: 1,
    reporter: [['line']],
    outputDir: '/tmp/page-check',
    use: {
        baseURL: process.env.PLAYWRIGHT_BASE_URL ?? 'http://localhost:9502',
        navigationTimeout: 15_000,
        ...devices['Desktop Chrome'],
    },
});
