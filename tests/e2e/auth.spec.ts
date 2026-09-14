import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { DEMO, expectSurface } from './support';

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function at(page: Page, width: number, height = 900): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width, height });
}

test.describe('signing in', () => {
    for (const width of [375, 768, 1280] as const) {
        test(`login at ${width}`, async ({ page }) => {
            await at(page, width, width === 375 ? 900 : 1000);
            await page.goto('/login');
            await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();
            await settled(page);

            await expect(page).toHaveScreenshot(`login-${width}.png`, { fullPage: true });
        });
    }

    test('is a page rather than a centred card, at every width', async ({ page }) => {
        for (const width of [375, 768, 1280]) {
            await at(page, width);
            await page.goto('/login');
            await expect(page.getByRole('heading', { name: 'Sign in' })).toBeVisible();

            const box = await page.getByRole('heading', { name: 'Sign in' }).boundingBox();
            expect(box, `no heading at ${width}`).not.toBeNull();
            expect(box!.x, `heading is centred at ${width}`).toBeLessThan(width / 3);

            expect(
                await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth),
                `page scrolls sideways at ${width}`,
            ).toBe(true);

            await expectSurface(page.locator('#app > div').first(), 'paper', 'the auth page');

            const panel = page.locator('aside');

            if (width >= 1024) {
                await expect(panel).toBeVisible();
                await expectSurface(panel, 'paperSunk', 'the auth page’s quiet column');
            } else {
                await expect(panel).toBeHidden();
            }
        }
    });

    test('the focus ring is the accent, not the form plugin\'s blue', async ({ page }) => {
        await at(page, 1280, 1000);
        await page.goto('/login');

        const email = page.locator('input[type="email"]');
        await email.focus();

        const style = await email.evaluate((el) => {
            const computed = getComputedStyle(el);

            return { border: computed.borderTopColor, shadow: computed.boxShadow };
        });

        expect(style.shadow).toContain('168, 87, 41');
        expect(style.border).not.toContain('37, 99, 235');
    });

    test('says what happened when the details are wrong', async ({ page }) => {
        await at(page, 375);
        await page.goto('/login');

        await page.locator('input[type="email"]').fill('nobody@paw.test');
        await page.locator('input[type="password"]').fill('not-the-password');
        await page.getByRole('button', { name: 'Sign in' }).click();

        const alert = page.getByRole('alert').filter({ hasText: /credentials|match/i }).first();
        await expect(alert).toBeVisible();
        await settled(page);

        await expect(page).toHaveScreenshot('login-error-375.png', { fullPage: true });
    });

    test('signs in from localhost even when APP_URL is 127.0.0.1', async ({ page, baseURL }) => {
        await at(page, 1280);
        const login = (baseURL ?? 'http://127.0.0.1:8129').replace('127.0.0.1', 'localhost') + '/login';

        await page.goto(login);
        await page.locator('input[type="email"]').fill(DEMO.ownerEmail);
        await page.locator('input[type="password"]').fill(DEMO.ownerPassword);
        await page.getByRole('button', { name: 'Sign in' }).click();

        await expect(page).toHaveURL(/\/diary/);
        expect(new URL(page.url()).hostname).toBe('localhost');
    });
});

const SIGNUP_EMAIL = `maya+${Date.now()}@willowstreet.example`;
test.describe('setting up a business at 375', () => {
    test('walks all five steps with the progress legible on each', async ({ page }) => {
        await at(page, 375, 1400);

        await page.goto('/register');
        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();
        await settled(page);
        await expect(page).toHaveScreenshot('setup-1-account-375.png', { fullPage: true });

        await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuemax', '6');
        await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '1');

        await page.getByLabel(/^Your name/).fill('Maya Chen');
        await page.getByLabel(/^Email/).fill(SIGNUP_EMAIL);
        await page.getByLabel(/^Password/).fill('correct-horse-battery');
        await page.getByLabel(/^Confirm password/).fill('correct-horse-battery');
        await page.getByRole('button', { name: 'Continue to business basics' }).click();

        await expect(page.getByRole('heading', { name: 'Where you are' })).toBeVisible();
        await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '2');
        await settled(page);
        await expect(page).toHaveScreenshot('setup-2-business-375.png', { fullPage: true });

        await page.getByLabel('Phone').fill('020 7946 0123');
        await page.getByLabel('Address', { exact: true }).fill('12 Willow Street');
        await page.getByLabel('Town or city').fill('London');
        await page.getByLabel('Postcode').fill('E8 3AA');
        await page.getByRole('button', { name: 'Save and continue' }).click();

        await expect(page.getByRole('heading', { name: 'What you do' })).toBeVisible();
        await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '3');
        await settled(page);
        await expect(page).toHaveScreenshot('setup-3-services-375.png', { fullPage: true });

        await page.getByRole('button', { name: 'Save and continue' }).click();

        await expect(page.getByRole('heading', { name: 'Who does it' })).toBeVisible();
        await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '4');
        await expect(page.getByText('Just you, for now')).toBeVisible();
        await settled(page);
        await expect(page).toHaveScreenshot('setup-4-people-375.png', { fullPage: true });

        await page.getByRole('button', { name: 'Save and continue' }).click();

        await expect(page.getByRole('heading', { name: 'When you are open' })).toBeVisible();
        await expect(page.getByRole('progressbar')).toHaveAttribute('aria-valuenow', '5');
        await settled(page);
        await expect(page).toHaveScreenshot('setup-5-hours-375.png', { fullPage: true });

        await page.getByRole('link', { name: /^Back to/ }).click();
        await expect(page.getByRole('heading', { name: 'Who does it' })).toBeVisible();
        await page.getByRole('link', { name: /^Back to/ }).click();
        await expect(page.getByRole('heading', { name: 'What you do' })).toBeVisible();
        await expect(page.getByLabel('Name').first()).not.toHaveValue('');
    });
});
