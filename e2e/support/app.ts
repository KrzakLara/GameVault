import { expect, type APIResponse, type Page } from '@playwright/test';

/** Accounts created by database/seeders/E2eSeeder.php (all use the same password). */
export const PASSWORD = 'password';
export const users = {
    buyer: 'buyer@e2e.test',
    admin: 'admin@e2e.test',
    customer: 'customer@e2e.test',
    racer: 'racer@e2e.test',
    rival: 'rival@e2e.test',
    limit: 'limit@e2e.test',
    rush: 'rush@e2e.test',
} as const;

/** Public order ids are ULIDs. */
export const ORDER_URL = /\/my-orders\/([0-9A-HJKMNP-TV-Z]{26})$/;

export async function signIn(page: Page, email: string): Promise<void> {
    await page.goto('/login');
    await page.getByLabel('Email address').fill(email);
    await page.getByLabel('Password', { exact: true }).fill(PASSWORD);
    await page.getByRole('button', { name: 'Log in' }).click();
    await expect(page.getByTestId('user-menu')).toBeVisible();
}

export function orderIdFrom(url: string): string {
    const match = ORDER_URL.exec(new URL(url).pathname);
    if (!match) {
        throw new Error(`Not an order page: ${url}`);
    }

    return match[1];
}

/**
 * Calls the checkout API directly with the browser's session, like a script or a second tab
 * would. Laravel accepts the CSRF token from the X-XSRF-TOKEN header (the same mechanism axios
 * uses in the app).
 */
export async function apiCheckout(page: Page, slug: string, idempotencyKey: string): Promise<APIResponse> {
    const xsrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
    if (!xsrf) {
        throw new Error('No XSRF-TOKEN cookie yet: open a page first');
    }

    return page.request.post(`/games/${slug}/checkout`, {
        headers: { Accept: 'application/json', 'X-XSRF-TOKEN': decodeURIComponent(xsrf.value) },
        data: { idempotency_key: idempotencyKey },
    });
}

export function orderRows(page: Page) {
    return page.locator('[data-testid^="order-row-"]');
}
