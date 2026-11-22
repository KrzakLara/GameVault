import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { signIn, users } from './support/app';

/**
 * Admin journeys on the existing admin routes. The fixture contains one fulfillment that gave up
 * (Ember Tactics, attempts_exhausted, retryable), created through the real checkout and failure
 * actions by E2eSeeder.
 */
test.describe('admin console', () => {
    test.describe.configure({ mode: 'serial' });

    let context: BrowserContext;
    let page: Page;

    test.beforeAll(async ({ browser }) => {
        context = await browser.newContext();
        page = await context.newPage();
    });

    test.afterAll(async () => {
        await context.close();
    });

    test('11. admin signs in', async () => {
        await signIn(page, users.admin);

        await expect(page.getByRole('banner').getByRole('link', { name: 'Admin' })).toBeVisible();
    });

    test('12. admin opens the dashboard (live aggregates) and Horizon', async () => {
        await page.getByRole('banner').getByRole('link', { name: 'Admin' }).click();

        await expect(page).toHaveURL(/\/admin$/);
        await expect(page.getByRole('heading', { level: 1 })).toHaveText('Overview');
        await expect(page.getByText('Total orders')).toBeVisible();
        const failures = page.getByRole('region', { name: 'Recent failures' });
        await expect(failures.getByRole('link', { name: /Ember Tactics/ })).toContainText('attempts_exhausted');

        // Horizon is reachable for administrators (customers get 403, journey 16).
        const horizon = await page.goto('/horizon');
        expect(horizon?.status()).toBe(200);
        await page.goto('/admin');
    });

    test('13. admin filters orders', async () => {
        await page.getByRole('navigation', { name: 'Admin' }).getByRole('link', { name: 'Orders' }).click();
        await expect(page).toHaveURL(/\/admin\/orders$/);

        await page.getByTestId('admin-filter-status').selectOption('failed');
        await expect(page).toHaveURL(/[?&]status=failed\b/);
        const rows = page.getByTestId('admin-orders-table').locator('[data-testid^="admin-order-"]');
        await expect(rows).toHaveCount(1);
        await expect(rows.first()).toContainText('Ember Tactics');
        await expect(rows.first()).toContainText('leo@e2e.test');

        // Search by customer e-mail prefix (server-side), combined with the status filter.
        await page.getByTestId('admin-order-search').fill('mia@');
        await expect(page).toHaveURL(/[?&]q=mia%40/);
        await expect(page.getByTestId('empty-state')).toBeVisible();
        await page.getByTestId('admin-filter-status').selectOption('');
        await expect(rows).toHaveCount(2); // Mia's two delivered orders
        await page.getByTestId('admin-order-search').fill('');
        await page.getByTestId('admin-filter-status').selectOption('failed');
        await expect(rows).toHaveCount(1);
    });

    test('14. admin inspects the failed fulfillment', async () => {
        await page.getByTestId('admin-orders-table').locator('[data-testid^="admin-order-"]').first().click();

        const drawer = page.getByTestId('order-drawer');
        await expect(drawer).toBeVisible();
        await expect(drawer).toContainText('attempts exhausted');
        await expect(drawer).toContainText(/Attempts this cycle\s*5/);
        await expect(drawer).not.toContainText('GVE2E-'); // no key material in admin views

        await page.getByRole('link', { name: 'Full timeline' }).click();
        await expect(page).toHaveURL(/\/admin\/orders\/[0-9A-HJKMNP-TV-Z]{26}$/);
        const timeline = page.getByTestId('admin-timeline');
        await expect(timeline).toContainText('processing → failed');
        await expect(page.getByTestId('order-status-failed')).toBeVisible();
    });

    test('15. admin retries the eligible fulfillment, which then delivers', async () => {
        await page.getByTestId('retry-order').click();
        await page.getByTestId('confirm-action').click();

        await expect(page.getByTestId('toast-success')).toContainText('Retry queued.');
        const timeline = page.getByTestId('admin-timeline');
        await expect(timeline).toContainText('failed → pending');
        await expect(timeline).toContainText('by Ada Admin');
        await expect(timeline).toContainText('admin_retry');
        await expect(page.getByRole('region', { name: 'Audit trail' })).toContainText('Ada Admin'); // privileged action audited

        // Horizon picks the job up; the admin page shows the outcome after a reload.
        await expect(async () => {
            await page.reload();
            await expect(page.getByTestId('order-status-delivered')).toBeVisible({ timeout: 1_000 });
        }).toPass({ timeout: 30_000 });
        await expect(page.getByTestId('admin-timeline')).toContainText('→ delivered');
    });
});

test('16. a non-admin cannot access admin pages, Horizon or admin actions', async ({ page }) => {
    await signIn(page, users.customer);
    await expect(page.getByRole('banner').getByRole('link', { name: 'Admin' })).toHaveCount(0);

    for (const path of ['/admin', '/admin/orders', '/admin/inventory', '/admin/outbox']) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBe(403);
        await expect(page.getByTestId('error-page')).toContainText('403');
    }

    const horizon = await page.goto('/horizon');
    expect(horizon?.status()).toBe(403);

    // A crafted request is refused on the server too, not just hidden in the UI.
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    const response = await page.request.post('/admin/outbox/requeue', {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf?.value ?? '') },
    });
    expect(response.status()).toBe(403);
});
