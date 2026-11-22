import { expect, test } from '@playwright/test';

// Fixture catalogue (database/seeders/E2eSeeder.php): 27 games, 13 Steam, 7 PlayStation, 7 Xbox.
const PLAYSTATION = ['cyber-arena', 'arcane-frontier', 'titan-forge', 'dream-circuit', 'hollow-citadel', 'last-light-protocol', 'ember-tactics'];

test.describe('storefront (guest)', () => {
    test('1. open the storefront: featured games, an explained trending rule, platforms', async ({ page }) => {
        const response = await page.goto('/');
        expect(response?.status()).toBe(200);
        await expect(page).toHaveTitle(/^Discover games · /);

        const featured = page.getByRole('region', { name: 'Featured' });
        await expect(featured.locator('[data-testid^="game-card-"]')).toHaveCount(4);
        await expect(featured.getByTestId('game-card-starlight-odyssey')).toBeVisible();

        // Trending is ranked by real purchases, and the page says so.
        await expect(page.getByTestId('trending-rule')).toHaveText('Ranking rule: Most purchased in the last 14 days.');
        await expect(
            page
                .getByRole('region', { name: /Trending/ })
                .getByRole('listitem')
                .first(),
        ).toContainText('Neon Drift');

        await expect(page.getByTestId('platform-Steam')).toContainText('13');
        await expect(page.getByTestId('platform-PlayStation')).toContainText('7');
        await expect(page.getByTestId('platform-Xbox')).toContainText('7');
    });

    test('2. search the catalogue (server-side, shareable URL)', async ({ page }) => {
        await page.goto('/games');
        await expect(page.getByTestId('result-count')).toHaveText('27 games');

        await page.getByTestId('catalog-search').fill('neon');
        await expect(page).toHaveURL(/[?&]search=neon\b/);
        await expect(page.getByTestId('result-count')).toHaveText('1 game');
        await expect(page.locator('[data-testid^="game-card-"]')).toHaveCount(1);
        await expect(page.getByTestId('game-card-neon-drift')).toBeVisible();

        // The URL alone reproduces the result: the filtering happened on the server.
        await page.goto('/games?search=zzz-no-such-game');
        await expect(page.getByTestId('empty-state')).toBeVisible();
    });

    test('3. filter games by platform', async ({ page }) => {
        await page.goto('/games');
        await page.getByTestId('filter-platform').selectOption('PlayStation');

        await expect(page).toHaveURL(/[?&]platform=PlayStation\b/);
        await expect(page.getByTestId('result-count')).toHaveText('7 games');
        const cards = page.locator('[data-testid^="game-card-"]');
        await expect(cards).toHaveCount(PLAYSTATION.length);
        const slugs = await cards.evaluateAll((elements) => elements.map((el) => (el.getAttribute('data-testid') ?? '').replace('game-card-', '')));
        expect(slugs.sort()).toEqual([...PLAYSTATION].sort());

        await page.getByTestId('filter-sort').selectOption('price_asc');
        await expect(page).toHaveURL(/[?&]sort=price_asc\b/);
        await expect(cards.first()).toHaveAttribute('data-testid', 'game-card-dream-circuit'); // €29.99, cheapest PS title
    });

    test('4. open a game detail page', async ({ page }) => {
        await page.goto('/games?platform=PlayStation');
        await page.getByTestId('game-card-cyber-arena').click();

        await expect(page).toHaveURL(/\/games\/cyber-arena$/);
        await expect(page.getByTestId('game-title')).toHaveText('Cyber Arena');
        await expect(page.getByTestId('price')).toContainText('€39.99');
        await expect(page.getByTestId('purchase-panel').getByTestId('stock-indicator')).toBeVisible();
        await expect(page.getByText('original fictional game')).toBeVisible();
        // A console title is never compared with PC market data.
        await expect(page.getByTestId('market-platform_not_tracked')).toContainText('PC storefronts only');
        // Guests are asked to sign in instead of buying.
        await expect(page.getByTestId('buy-button')).toHaveCount(0);
        await expect(page.getByTestId('login-to-buy')).toBeVisible();
    });
});
