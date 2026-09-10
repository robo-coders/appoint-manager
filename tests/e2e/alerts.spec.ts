import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

/**
 * One end-to-end confirmation per call site the alert system took over, against
 * a real browser: the toast actually appears, in the right tone, with a retry
 * that actually retries.
 *
 * `tests/js/toast.test.ts` asserts the store and the two components in jsdom.
 * This asserts that the wiring is real on the screens that fire them — a
 * component that is perfectly correct in a unit test still shows nothing if
 * `ToastContainer` was never mounted on the page doing the firing.
 */

const INK = 'rgb(24, 23, 20)';
const TERRACOTTA = 'rgb(168, 87, 41)';

async function freezeTime(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
}

const toasts = (page: Page) => page.locator('[data-testid="toast"]');

async function background(page: Page, index = 0): Promise<string> {
    return toasts(page).nth(index).evaluate((el) => getComputedStyle(el).backgroundColor);
}

test.describe('the toast system, on the screens that fire it', () => {
    test('Calendar Sync: copying a feed address confirms with an ink toast', async ({ page, context }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await freezeTime(page);
        await page.goto('/settings/calendar-sync');

        const copy = page.locator('[data-testid="calendar-feed-copy"]').first();
        await expect(copy).toBeVisible();

        // The label is the control's name, not a status — that swap is what the
        // toast replaced.
        await expect(copy).toHaveText('Copy');

        await copy.click();

        await expect(toasts(page)).toHaveCount(1);
        await expect(toasts(page).first()).toContainText('Link copied');
        await expect(toasts(page).first()).toHaveAttribute('data-tone', 'success');
        await expect(toasts(page).first()).toHaveAttribute('role', 'status');
        expect(await background(page)).toBe(INK);

        // Still says Copy afterwards, because the button never became a label.
        await expect(copy).toHaveText('Copy');
    });

    test('the toast lives in a polite live region and clears itself', async ({ page, context }) => {
        await context.grantPermissions(['clipboard-read', 'clipboard-write']);
        await page.goto('/settings/calendar-sync');
        await page.locator('[data-testid="calendar-feed-copy"]').first().click();

        const region = page.locator('[data-testid="toast-container"]');

        await expect(region).toHaveAttribute('aria-live', 'polite');
        await expect(region).toHaveAttribute('aria-atomic', 'false');
        expect(await region.evaluate((el) => el.parentElement === document.body)).toBe(true);

        await expect(toasts(page)).toHaveCount(0, { timeout: 15_000 });
    });

    test('Client History: saving a note confirms once per save, not once per message', async ({ page }) => {
        await freezeTime(page);
        await page.goto('/customers');
        await page.locator('table tbody tr a[href*="/customers/"]').first().click();

        const note = page.locator('textarea').first();
        const save = page.locator('[data-testid="customer-notes-save"]');
        await expect(note).toBeVisible();

        // The metadata line is record-keeping and stays put; the confirmation
        // is the thing that moved to a toast.
        const meta = page.getByText(/Last edited by|Not edited yet/).first();
        const before = await meta.innerText();

        await note.fill('Allow ten more minutes for the dryer.');
        await save.click();

        await expect(toasts(page)).toHaveCount(1);
        await expect(toasts(page).first()).toHaveAttribute('data-tone', 'success');
        expect(await background(page)).toBe(INK);

        // The same message a second time still announces itself. This is the
        // bug the boot-time flash consumer fixed: a `watch` on the prop
        // compares by value and dropped the repeat.
        await note.fill('Allow fifteen more minutes for the dryer.');
        await save.click();

        await expect(toasts(page)).toHaveCount(2);

        await expect(meta).not.toHaveText(before);
    });
});

test.describe('the toast system, on the public manage-booking island', () => {
    /** The `public_token` of the first upcoming booking, from its record page. */
    async function liveToken(page: Page): Promise<string> {
        await page.goto('/bookings?status=confirmed');
        await page.locator('table[aria-label="Bookings"] tbody tr a').first().click();
        await expect(page.getByRole('link', { name: '← Bookings' })).toBeVisible();
        await page.goto(page.url());

        const token = await page.evaluate(() => {
            const root = document.getElementById('app');
            const payload = root ? JSON.parse(root.dataset.page ?? '{}') : {};

            return payload?.props?.booking?.public_token ?? '';
        });

        expect(token).toMatch(/^[0-9a-f-]{36}$/);

        return token;
    }

    test('a failed cancel raises a terracotta toast whose retry really retries', async ({ page }) => {
        const token = await liveToken(page);

        await page.goto(`/book/b/${token}`);
        await expect(page.getByRole('heading').first()).toBeVisible();

        let attempts = 0;

        await page.route('**/cancel', async (route) => {
            attempts += 1;
            await route.fulfill({ status: 500, contentType: 'application/json', body: '{}' });
        });

        await page.getByRole('button', { name: /^Cancel/ }).first().click();
        await page.getByRole('button', { name: 'Yes, cancel it' }).click();

        await expect(toasts(page)).toHaveCount(1);

        const failure = toasts(page).first();

        await expect(failure).toHaveAttribute('data-tone', 'error');
        await expect(failure).toHaveAttribute('role', 'alert');
        await expect(failure).toContainText('still booked');
        expect(await background(page)).toBe(TERRACOTTA);
        expect(attempts).toBe(1);

        // An error with something to do about it does not vanish on a timer.
        await page.waitForTimeout(6000);
        await expect(toasts(page)).toHaveCount(1);

        await failure.locator('[data-testid="toast-action"]').click();

        await expect.poll(() => attempts).toBe(2);
    });
});
