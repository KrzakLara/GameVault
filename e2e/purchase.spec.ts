import { expect, test, type BrowserContext, type Page } from '@playwright/test';
import { orderIdFrom, signIn, users } from './support/app';

/**
 * One customer's journey from sign-in to a copied key, as consecutive steps sharing a browser
 * session. Fulfillment is real: Horizon processes the queued job against the E2E database.
 */
test.describe.configure({ mode: 'serial' });

let context: BrowserContext;
let page: Page;
let orderId: string;
let revealedKey: string;
const revealRequests: string[] = [];

test.beforeAll(async ({ browser, baseURL }) => {
    context = await browser.newContext();
    await context.grantPermissions(['clipboard-read', 'clipboard-write'], { origin: baseURL });
    page = await context.newPage();
    page.on('request', (request) => {
        if (request.url().endsWith('/reveal')) {
            revealRequests.push(request.method());
        }
    });
});

test.afterAll(async () => {
    await context.close();
});

test('5. sign in', async () => {
    await signIn(page, users.buyer);

    await expect(page).toHaveURL(/\/dashboard$/);
    await expect(page.getByRole('heading', { level: 1 })).toHaveText('Welcome back, Ava');
});

test('6. perform a simulated purchase', async () => {
    await page.goto('/games/aurora-drift');
    // The PC title shows the (stubbed) CheapShark reference with its source, scope and currency.
    await expect(page.getByTestId('market-price')).toContainText('PC digital storefronts offers via CheapShark, in USD');
    await expect(page.getByTestId('purchase-panel')).toContainText('Simulated checkout: no payment is taken');
    await expect(page.getByTestId('buy-button')).toContainText('simulated');

    const checkout = page.waitForResponse((r) => r.url().endsWith('/games/aurora-drift/checkout') && r.request().method() === 'POST');
    await page.getByTestId('buy-button').click();
    const response = await checkout;

    expect(response.status()).toBe(201);
    // The client never sends a price: only the idempotency key.
    expect(Object.keys(response.request().postDataJSON() as object)).toEqual(['idempotency_key']);
    await expect(page).toHaveURL(/\/my-orders\/[0-9A-HJKMNP-TV-Z]{26}$/);
    orderId = orderIdFrom(page.url());
    await expect(page.getByTestId('fulfillment-progress')).toBeVisible();
});

test('7. open My Orders', async () => {
    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'My orders' }).click();

    await expect(page).toHaveURL(/\/my-orders$/);
    const row = page.getByTestId(`order-row-${orderId}`);
    await expect(row).toContainText('Aurora Drift');
    await expect(row).toContainText('€24.99');
});

test('8. wait for the fulfillment status to reach Delivered', async () => {
    await page.getByTestId(`order-row-${orderId}`).click();
    await expect(page).toHaveURL(new RegExp(`/my-orders/${orderId}$`));

    // The order page polls the server; Horizon delivers in the background (1.2 s simulated supplier).
    await expect(page.getByTestId('order-status-delivered')).toBeVisible({ timeout: 30_000 });
    await expect(page.getByTestId('fulfillment-progress')).toContainText('Delivered');
});

test('9. reveal the purchased key (only on explicit action)', async () => {
    await expect(page.getByTestId('revealed-key')).toHaveCount(0);
    expect(revealRequests).toEqual([]); // nothing fetched the key automatically

    const reveal = page.waitForResponse((r) => r.url().endsWith(`/my-orders/${orderId}/reveal`));
    await page.getByTestId('reveal-key').click();
    expect((await reveal).status()).toBe(200);

    const key = page.getByTestId('revealed-key');
    await expect(key).toHaveText(/^GVE2E-[0-9A-F]{5}-[0-9A-F]{5}$/);
    revealedKey = (await key.textContent()) ?? '';
    expect(revealRequests).toEqual(['POST']);
});

test('10. copy the key, which is never persisted and is cleared on leaving the page', async () => {
    await page.getByTestId('copy-key').click();
    await expect(page.getByTestId('copy-key')).toHaveText(/Copied/);
    expect(await page.evaluate(() => navigator.clipboard.readText())).toBe(revealedKey);

    const webStorage = await page.evaluate(() => JSON.stringify({ local: { ...localStorage }, session: { ...sessionStorage } }));
    expect(webStorage).not.toContain(revealedKey);

    await page.getByRole('navigation', { name: 'Main' }).getByRole('link', { name: 'My orders' }).click();
    await expect(page).toHaveURL(/\/my-orders$/);
    await page.goBack();
    await expect(page).toHaveURL(new RegExp(`/my-orders/${orderId}$`));
    await expect(page.getByTestId('reveal-key')).toBeVisible();
    await expect(page.getByTestId('revealed-key')).toHaveCount(0);
    expect(await page.content()).not.toContain(revealedKey);
});
