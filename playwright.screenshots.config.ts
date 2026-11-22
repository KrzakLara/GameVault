import { defineConfig, devices } from '@playwright/test';

/**
 * Captures the README screenshots from the REAL running application (the E2E stack with its
 * deterministic fixtures): `scripts/screenshots.sh`. Not part of the test suite.
 */
export default defineConfig({
    testDir: './e2e/screenshots',
    testMatch: '*.shots.ts',
    outputDir: './test-results/screenshots',
    workers: 1,
    retries: 0,
    timeout: 90_000,
    reporter: [['list']],
    use: {
        baseURL: process.env.E2E_BASE_URL ?? 'http://127.0.0.1:8001',
        locale: 'en-GB',
        timezoneId: 'Europe/Zagreb',
        colorScheme: 'dark',
    },
    projects: [{ name: 'desktop', use: { ...devices['Desktop Chrome'], viewport: { width: 1440, height: 900 }, deviceScaleFactor: 1 } }],
});
