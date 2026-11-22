import { expect, test } from '@playwright/test';
import { apiCheckout, orderIdFrom, orderRows, signIn, users } from './support/app';

test('17. a sold-out product cannot be bought, including when the last key goes mid-visit', async ({ browser }) => {
    const racer = await browser.newContext();
    const rival = await browser.newContext();
    const page = await racer.newPage();
    const rivalPage = await rival.newPage();

    try {
        await signIn(page, users.racer);

        // Already sold out: shown as such, and the buy button is disabled.
        await page.goto('/games/last-light-protocol');
        await expect(page.getByTestId('purchase-panel').getByTestId('stock-indicator')).toHaveText(/Sold out/);
        await expect(page.getByTestId('buy-button')).toBeDisabled();

        // The last key: Rita sees it in stock, then Rex buys it before she clicks.
        await page.goto('/games/final-key-heist');
        await expect(page.getByTestId('purchase-panel').getByTestId('stock-indicator')).toContainText('1 left');

        await signIn(rivalPage, users.rival);
        await rivalPage.goto('/games/final-key-heist');
        await rivalPage.getByTestId('buy-button').click();
        await expect(rivalPage).toHaveURL(/\/my-orders\/[0-9A-HJKMNP-TV-Z]{26}$/);

        const checkout = page.waitForResponse((r) => r.url().endsWith('/games/final-key-heist/checkout'));
        await page.getByTestId('buy-button').click();
        expect((await checkout).status()).toBe(409);

        await expect(page.getByTestId('checkout-sold_out')).toBeVisible();
        await expect(page.getByTestId('purchase-panel').getByTestId('stock-indicator')).toHaveText(/Sold out/); // refreshed from the server
        await expect(page.getByTestId('buy-button')).toBeDisabled();

        await page.goto('/my-orders');
        await expect(orderRows(page)).toHaveCount(0); // the losing attempt created nothing
    } finally {
        await racer.close();
        await rival.close();
    }
});

test('18. a failing external pricing API degrades to a notice; the page and checkout still work', async ({ page }) => {
    // The stub answers 503 for this title; the app retries once, then reports it as unavailable.
    await page.goto('/games/signal-lost');

    await expect(page.getByTestId('game-title')).toHaveText('Signal Lost');
    await expect(page.getByTestId('price')).toContainText('€18.99');
    await expect(page.getByTestId('market-temporarily_unavailable')).toHaveText(/Market data is temporarily unavailable/);
    await expect(page.getByTestId('market-price')).not.toContainText('$'); // no invented number
    await expect(page.getByTestId('login-to-buy')).toBeVisible();
});

test('19. the checkout rate limit is enforced by the server and explained in the UI', async ({ page }) => {
    await signIn(page, users.limit);
    await page.goto('/games/overclocked');

    // Ten calls with ONE idempotency key: one order plus nine replays, all counted by the limiter
    // (10 per minute per user).
    const key = crypto.randomUUID();
    const statuses: number[] = [];
    for (let i = 0; i < 10; i++) {
        statuses.push((await apiCheckout(page, 'overclocked', key)).status());
    }
    expect(statuses).toEqual([201, 200, 200, 200, 200, 200, 200, 200, 200, 200]);

    const checkout = page.waitForResponse((r) => r.url().endsWith('/games/overclocked/checkout'));
    await page.getByTestId('buy-button').click();
    const response = await checkout;
    expect(response.status()).toBe(429);
    expect(Number(response.headers()['retry-after'])).toBeGreaterThan(0);

    await expect(page.getByTestId('checkout-rate_limited')).toContainText(/Too many checkout attempts\. Please wait \d+ seconds/);
    await expect(page).toHaveURL(/\/games\/overclocked$/);

    await page.goto('/my-orders');
    await expect(orderRows(page)).toHaveCount(1);
});

test('20. duplicate checkout submissions never create a second order', async ({ page }) => {
    await signIn(page, users.rush);

    // (a) A double click: one order.
    await page.goto('/games/twin-strike');
    const posts: string[] = [];
    page.on('request', (request) => {
        if (request.method() === 'POST' && request.url().endsWith('/checkout')) {
            posts.push(request.postData() ?? '');
        }
    });
    await page.getByTestId('buy-button').dblclick();
    await expect(page).toHaveURL(/\/my-orders\/[0-9A-HJKMNP-TV-Z]{26}$/);
    const first = orderIdFrom(page.url());
    expect(new Set(posts).size).toBe(1); // every request carried the same intent key

    // (b) The server accepts the order but the response is lost (network drop). Clicking again
    // re-sends the same intent key and lands on the SAME order.
    await page.goto('/games/twin-strike');
    let createdId = '';
    await page.route(
        '**/games/twin-strike/checkout',
        async (route) => {
            const response = await route.fetch();
            expect(response.status()).toBe(201);
            createdId = ((await response.json()) as { id: string }).id;
            await route.abort('internetdisconnected');
        },
        { times: 1 },
    );

    await page.getByTestId('buy-button').click();
    await expect(page.getByTestId('checkout-error')).toContainText('Retrying is safe');
    expect(createdId).toMatch(/^[0-9A-HJKMNP-TV-Z]{26}$/);

    const replay = page.waitForResponse((r) => r.url().endsWith('/games/twin-strike/checkout'));
    await page.getByTestId('buy-button').click();
    expect((await replay).status()).toBe(200); // a replay, not a new order
    await expect(page).toHaveURL(new RegExp(`/my-orders/${createdId}$`));

    await page.goto('/my-orders');
    await expect(orderRows(page)).toHaveCount(2);
    await expect(page.getByTestId(`order-row-${first}`)).toBeVisible();
    await expect(page.getByTestId(`order-row-${createdId}`)).toBeVisible();
});
