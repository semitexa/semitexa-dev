import { defineConfig, devices } from '@playwright/test';

// Skill-owned release browser smoke. Deployed by release-auto-checks.sh into
// <release-root>/var/release-smoke/ and run via the e2e-runner service, which
// shares the app container's network namespace. The demo tenant is addressed
// by real Host-based resolution: Chromium maps the tenant domain to 127.0.0.1
// (host-resolver-rules), the app then sees Host: demo.rls.semitexa.test:<port>
// exactly like traffic through the shared router.
const port = process.env.SWOOLE_PORT ?? '9502';
const host = process.env.RELEASE_SMOKE_HOST ?? 'demo.rls.semitexa.test';

export default defineConfig({
  testDir: '.',
  testMatch: /release-smoke\.spec\.ts$/,
  timeout: 30_000,
  retries: 1,
  workers: 2,
  reporter: [['list']],
  projects: [
    {
      name: 'chromium',
      use: {
        ...devices['Desktop Chrome'],
        baseURL: `http://${host}:${port}`,
        launchOptions: {
          args: [`--host-resolver-rules=MAP ${host} 127.0.0.1`],
        },
      },
    },
  ],
});
