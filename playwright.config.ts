import { defineConfig, devices } from '@playwright/test';

/**
 * Browser journeys against the REAL application: the isolated E2E stack from docker-compose.yml
 * (profile "e2e": PHP server, MySQL database gamevault_e2e, Redis, Horizon, scheduler and a
 * CheapShark stub). Run it with scripts/e2e.sh, which rebuilds the E2E database from
 * deterministic fixtures first. See e2e/README.md.
 */
export default defineConfig({
    testDir: './e2e',
    testMatch: '*.spec.ts',
    outputDir: './test-results',
    // One seeded database: journeys own their fixtures, but they still run one at a time.
    fullyParallel: false,
    workers: 1,
    // A flaky journey must fail visibly, not pass on a retry.
    retries: 0,
    forbidOnly: !!process.env.CI,
    timeout: 60_000,
    expect: { timeout: 10_000 },
    reporter: [['list'], ['html', { outputFolder: 'playwright-report', open: 'never' }], ['json', { outputFile: 'test-results/results.json' }]],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8001',
        locale: 'en-GB',
        timezoneId: 'Europe/Zagreb',
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
    },
    projects: [{ name: 'chromium', use: { ...devices['Desktop Chrome'] } }],
});
