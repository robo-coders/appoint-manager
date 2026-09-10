import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

/**
 * Managing a booking, as a customer reaches it: a texted link, on a phone, with
 * no session of their own. `.design/mockups/booking/manage-booking.dc.html`.
 *
 * `ManageBookingTest` asserts what the endpoints do. This asserts what somebody
 * holding the link sees — that a dead one is a sentence rather than a stack
 * trace, and that a live one carries the reference, the salon's code and a way
 * to reach a person, which is the mockup's footer.
 *
 * The token is read out of the operator record's own props rather than written
 * here, because the seed is rebuilt every run and a hard-coded token would go
 * stale silently.
 */

const PHONE = { width: 375, height: 900 };

async function freezeTime(page: Page): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
}

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

/** The `public_token` of the first upcoming booking, from its record page. */
async function liveToken(page: Page): Promise<string> {
    await page.goto('/bookings?status=confirmed');
    await page.locator('table[aria-label="Bookings"] tbody tr a').first().click();
    await expect(page.getByRole('link', { name: '← Bookings' })).toBeVisible();

    /*
     * A full load of the record, not the Inertia visit that got us here:
     * `data-page` on the root carries the *initial* payload only, so after a
     * client-side visit it still holds the list's props rather than the
     * record's.
     */
    await page.goto(page.url());

    const token = await page.evaluate(() => {
        const root = document.getElementById('app');
        const payload = root ? JSON.parse(root.dataset.page ?? '{}') : {};

        return payload?.props?.booking?.public_token ?? '';
    });

    expect(token).toMatch(/^[0-9a-f-]{36}$/);

    return token;
}

test.describe('a link that is no longer active', () => {
    test('is a calm sentence, not a framework error', async ({ page }) => {
        await freezeTime(page);
        await page.setViewportSize(PHONE);
        await page.goto('/book/b/00000000-0000-4000-8000-000000000000');
        await settled(page);

        await expect(page.getByText('This booking link is no longer active')).toBeVisible();
        await expect(page.getByText('There is nothing at this address')).toHaveCount(0);

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await expect(page).toHaveScreenshot('manage-inactive-375.png', { fullPage: true });
    });

    test('answers a token of the wrong shape the same way', async ({ page }) => {
        await page.goto('/book/b/abc');

        await expect(page.getByText('This booking link is no longer active')).toBeVisible();
    });
});

test.describe('a live link', () => {
    test('states the appointment and carries the mockup footer', async ({ page }) => {
        const token = await liveToken(page);

        await freezeTime(page);
        await page.setViewportSize(PHONE);
        await page.goto(`/book/b/${token}`);
        await settled(page);

        // The appointment, in the dominant heading the booking page also uses.
        await expect(page.locator('h1.text-34')).toBeVisible();

        /*
         * The footer. Not anchored with `^`: Playwright does not normalise
         * whitespace for a regex, and the span is indented in the template.
         *
         * The salon code and the message link are both conditional on tenant
         * data the demo seed does not carry — no postcode, no phone — so this
         * asserts the reference, and that the link, where it exists at all, is
         * a message rather than something else.
         */
        await expect(page.getByText(/Ref [0-9a-f]{8}/)).toBeVisible();

        const message = page.getByRole('link', { name: 'Message the salon' });

        if (await message.count()) {
            await expect(message).toHaveAttribute('href', /^sms:/);
        }

        expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
        await expect(page).toHaveScreenshot('manage-live-375.png', { fullPage: true });
    });

    test('opens the picker on the same engine the booking page uses', async ({ page }) => {
        const token = await liveToken(page);

        await freezeTime(page);
        await page.setViewportSize(PHONE);
        await page.goto(`/book/b/${token}`);
        await settled(page);

        await page.getByRole('button', { name: /Move this appointment/ }).click();

        // Times, or an explicit statement that there are none. Never a blank grid.
        await expect(
            page.locator('[role="group"][aria-label="Morning times"], [role="group"][aria-label="Afternoon times"]')
                .first()
                .or(page.getByText(/No availability in the next/))
                .or(page.getByText('Closed this day')),
        ).toBeVisible();
    });
});
