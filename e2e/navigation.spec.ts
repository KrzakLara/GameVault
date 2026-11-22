import { expect, test } from '@playwright/test';
import { signIn, users } from './support/app';

/**
 * Release-gate check, in addition to the 20 journeys: every internal link on the main storefront,
 * account and admin pages resolves without a 4xx/5xx (followed as an admin, who can see the most).
 */
test('navigation check: no broken internal links on the main pages', async ({ page }) => {
    await signIn(page, users.admin);

    const pages = [
        '/',
        '/games',
        '/games?platform=Xbox&sort=price_desc',
        '/games/neon-drift',
        '/dashboard',
        '/my-orders',
        '/settings/profile',
        '/settings/password',
        '/admin',
        '/admin/orders',
        '/admin/inventory',
        '/admin/outbox',
    ];
    const checked = new Set<string>();
    const broken: string[] = [];

    for (const path of pages) {
        const response = await page.goto(path);
        expect(response?.status(), path).toBeLessThan(400);

        const origin = new URL(page.url()).origin;
        const hrefs = await page.locator('a[href]').evaluateAll((links) => links.map((link) => (link as HTMLAnchorElement).href));
        for (const href of hrefs) {
            const url = new URL(href);
            const key = url.pathname + url.search;
            // Horizon is checked by journeys 12 and 16; everything else internal is followed.
            if (url.origin !== origin || checked.has(key) || url.pathname.startsWith('/horizon')) {
                continue;
            }
            checked.add(key);
            const result = await page.request.get(url.toString());
            if (result.status() >= 400) {
                broken.push(`${result.status()} ${key} (linked from ${path})`);
            }
        }
    }

    expect(broken).toEqual([]);
    expect(checked.size).toBeGreaterThan(25);
});
