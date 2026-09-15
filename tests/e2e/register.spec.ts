import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function at(page: Page, width: number, height = 1000): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width, height });
}

const address = (tag: string) => `maya+${tag}${Date.now()}@willowstreet.example`;

const PASSWORD = 'correct-horse-battery';

const field = (page: Page, label: RegExp) => page.getByLabel(label);

const YOUR_NAME = /^Your name/;
const EMAIL = /^Email/;
const PASSWORD_FIELD = /^Password/;
const CONFIRMATION = /^Confirm password/;

async function fillEverything(page: Page, email: string, confirmation = PASSWORD): Promise<void> {
    await field(page, YOUR_NAME).fill('Maya Chen');
    await field(page, EMAIL).fill(email);
    await field(page, PASSWORD_FIELD).fill(PASSWORD);
    await field(page, CONFIRMATION).fill(confirmation);
}

async function expectNothingLost(page: Page, email: string, confirmation = PASSWORD): Promise<void> {
    await expect(field(page, YOUR_NAME)).toHaveValue('Maya Chen');
    await expect(field(page, EMAIL)).toHaveValue(email);
    await expect(field(page, PASSWORD_FIELD)).toHaveValue(PASSWORD);
    await expect(field(page, CONFIRMATION)).toHaveValue(confirmation);
}

test.describe('setting up a business', () => {
    test('screen one, and the screen after it, drawn in the same system', async ({ page }) => {
        await at(page, 1280, 1100);
        await page.goto('/register');
        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();

        await expect(page.getByText('DiaryDesk setup · Your account')).toBeVisible();
        await expect(page.getByText('STEP 1 OF 5').first()).toBeVisible();
        await expect(page.getByText('Already set up?')).toBeVisible();

        await settled(page);
        await expect(page).toHaveScreenshot('register-1280.png', { fullPage: true });

        await at(page, 375, 1200);
        const progress = page.getByRole('progressbar');
        await expect(progress).toHaveAttribute('aria-valuemin', '1');
        await expect(progress).toHaveAttribute('aria-valuemax', '5');
        await expect(progress).toHaveAttribute('aria-valuenow', '1');
        await expect(progress).toHaveAttribute('aria-valuetext', 'Step 1 of 5, Your account');

        await settled(page);
        await expect(page).toHaveScreenshot('register-375.png', { fullPage: true });

        await at(page, 1280, 1100);
        await fillEverything(page, address('pair'));
        await page.getByRole('button', { name: 'Continue to business basics' }).click();

        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();

        await expect(field(page, /^Business name/)).toHaveValue('');

        await settled(page);
        await expect(page).toHaveScreenshot('register-2-basics-1280.png', { fullPage: true });
    });

    test('the confirmation not matching is said before anything is sent', async ({ page }) => {
        await at(page, 1280, 1100);
        await page.goto('/register');

        const email = address('mismatch');
        await fillEverything(page, email, 'correct-horse-batery');

        await expect(page.getByText('Those two passwords do not match.')).toBeVisible();

        await page.getByRole('button', { name: 'Continue to business basics' }).click();

        await expect(page).toHaveURL(/\/register$/);
        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();
        await expectNothingLost(page, email, 'correct-horse-batery');

        await settled(page);
        await expect(page).toHaveScreenshot('register-mismatch-1280.png', { fullPage: true });

        await field(page, CONFIRMATION).fill(PASSWORD);
        await expect(page.getByText('Those two passwords do not match.')).toBeHidden();
    });

    test('an address that is already registered is answered with the door', async ({ page }) => {
        const email = address('dupe');

        await at(page, 1280, 1100);
        await page.goto('/register');
        await fillEverything(page, email);
        await page.getByRole('button', { name: 'Continue to business basics' }).click();
        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();

        await page.context().clearCookies();
        await page.goto('/register');
        await fillEverything(page, email);
        await page.getByRole('button', { name: 'Continue to business basics' }).click();

        const message = page.getByText('An account with this email already exists');
        await expect(message).toBeVisible();

        const door = message.getByRole('link', { name: 'sign in instead' });
        await expect(door).toHaveAttribute('href', /\/login$/);

        const described = await field(page, EMAIL).getAttribute('aria-describedby');
        expect(described).toBeTruthy();
        await expect(page.locator(`#${described}`)).toContainText('already exists');

        await expectNothingLost(page, email);

        await settled(page);
        await expect(page).toHaveScreenshot('register-duplicate-1280.png', { fullPage: true });

        await door.click();
        await expect(page).toHaveURL(/\/login$/);
    });

    test('a request that never arrives says so, and keeps the form', async ({ page }) => {
        await at(page, 1280, 1100);
        await page.goto('/register');

        const email = address('offline');
        await fillEverything(page, email);

        await page.route('**/register', (route) => route.abort('failed'));

        await page.getByRole('button', { name: 'Continue to business basics' }).click();

        await expect(page.getByText('Not sent')).toBeVisible();
        await expect(page.getByText('Everything you typed is still here')).toBeVisible();

        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();
        await expectNothingLost(page, email);

        const button = page.getByRole('button', { name: 'Continue to business basics' });
        await expect(button).toBeEnabled();

        await settled(page);
        await expect(page).toHaveScreenshot('register-not-sent-1280.png', { fullPage: true });

        await page.getByRole('button', { name: 'Dismiss' }).click();
        await expect(page.getByText('Not sent')).toBeHidden();

        await page.unroute('**/register');
        await page.getByRole('button', { name: 'Continue to business basics' }).click();
        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();
    });

    test('the tenth failure locks the form with a sentence rather than a 429 page', async ({ page }) => {
        const email = address('locked');

        await at(page, 1280, 1100);
        await page.goto('/register');
        await fillEverything(page, email);
        await page.getByRole('button', { name: 'Continue to business basics' }).click();
        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();

        await page.context().clearCookies();

        for (let attempt = 0; attempt < 10; attempt++) {
            await page.goto('/register');
            await fillEverything(page, email);
            await page.getByRole('button', { name: 'Continue to business basics' }).click();
            await expect(page.getByText('An account with this email already exists')).toBeVisible();
        }

        await page.getByRole('button', { name: 'Continue to business basics' }).click();

        await expect(page.getByText(/^Too many attempts\. Try again in \d+ (seconds|minutes)\.$/)).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();

        await expect(field(page, EMAIL)).toHaveJSProperty('ariaInvalid', null);
        await expectNothingLost(page, email);

        await settled(page);
        await expect(page).toHaveScreenshot('register-locked-1280.png', { fullPage: true });
    });
});
