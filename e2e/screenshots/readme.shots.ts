import { expect, test, type Page } from '@playwright/test';
import { signIn, users } from '../support/app';

const OUT = 'docs/screenshots';

// JPEG keeps the illustrated pages small enough for the README (PNG is several times larger for artwork).
async function shot(page: Page, name: string, fullPage = false): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.screenshot({ path: `${OUT}/${name}.jpg`, type: 'jpeg', quality: 85, fullPage, animations: 'disabled' });
}

test('storefront', async ({ page }) => {
    await page.goto('/');
    await expect(page.getByTestId('trending-rule')).toBeVisible();
    await shot(page, 'discover');

    await page.goto('/games?platform=Steam&sort=price_asc');
    await expect(page.getByTestId('result-count')).toBeVisible();
    await shot(page, 'catalogue');

    // A console title: shows the honest "not applicable" market state, no third-party prices.
    await page.goto('/games/cyber-arena');
    await expect(page.getByTestId('market-platform_not_tracked')).toBeVisible();
    await shot(page, 'game-detail');
});

test('mobile storefront', async ({ browser }) => {
    const context = await browser.newContext({
        viewport: { width: 390, height: 844 },
        deviceScaleFactor: 2,
        isMobile: true,
        hasTouch: true,
        colorScheme: 'dark',
    });
    const page = await context.newPage();
    await page.goto('/');
    await expect(page.getByTestId('trending-rule')).toBeVisible();
    await shot(page, 'mobile-discover');
    await context.close();
});

test('purchase, delivery and key reveal', async ({ page }) => {
    await signIn(page, users.buyer);
    await page.goto('/games/twin-strike');
    await expect(page.getByTestId('buy-button')).toBeEnabled();
    await expect(page.getByTestId(/^market-/).first()).toBeVisible(); // the deferred market card has resolved
    await shot(page, 'checkout');
    await page.getByTestId('buy-button').click();
    await expect(page).toHaveURL(/\/my-orders\/[0-9A-Z]{26}$/);
    await expect(page.getByTestId('order-status-delivered')).toBeVisible({ timeout: 30_000 });
    await page.getByTestId('reveal-key').click();
    await expect(page.getByTestId('revealed-key')).toBeVisible();
    await shot(page, 'order-delivered');

    await page.goto('/my-orders');
    await expect(page.getByTestId('order-list')).toBeVisible();
    await shot(page, 'my-orders');
});

test('admin console', async ({ page }) => {
    await signIn(page, users.admin);
    await page.goto('/admin');
    await expect(page.getByText('Total orders')).toBeVisible();
    await shot(page, 'admin-dashboard');

    await page.goto('/admin/orders?status=failed');
    await page.getByTestId('admin-orders-table').locator('[data-testid^="admin-order-"]').first().click();
    await expect(page.getByTestId('order-drawer')).toBeVisible();
    await shot(page, 'admin-orders');

    await page.goto('/admin/inventory');
    await expect(page.getByTestId('inventory-table')).toBeVisible();
    await shot(page, 'admin-inventory');

    await page.goto('/admin/outbox');
    await expect(page.getByTestId('outbox-table')).toBeVisible();
    await shot(page, 'admin-outbox');
});
