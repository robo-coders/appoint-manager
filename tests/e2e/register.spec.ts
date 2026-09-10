import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';

/**
 * Setting up a business: screen one, in a real browser.
 *
 * Signed out, so it belongs to the `public` project for the reason
 * `auth.spec.ts` gives — an operator session on `/register` is a redirect
 * straight back out, and a spec carrying one can never see the form.
 *
 * Two jobs. The first is the look: `/register` is step one of six and steps two
 * to six are `Onboarding/Index.vue`, so the two are snapshotted here to be
 * compared against each other rather than only against their own baselines. A
 * screenshot of one page proves it has not changed; a screenshot of two proves
 * they belong to the same product.
 *
 * The second is the three ways this form fails in front of somebody, and the
 * one property that has to hold through all of them: **nothing typed is ever
 * lost.** Each case ends by reading every field back.
 */

/** Wait for the fonts the design depends on, or the first run snapshots Arial. */
async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function at(page: Page, width: number, height = 1000): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width, height });
}

/** Unique per run: `users.email` is unique and this suite is run by hand too. */
const address = (tag: string) => `maya+${tag}${Date.now()}@willowstreet.example`;

const PASSWORD = 'correct-horse-battery';

/*
 * Every field carries a required marker inside its `<label>` — `ui/Label`
 * appends an `aria-hidden` asterisk — so the label *text* is "Password*" and an
 * exact string match finds nothing. Anchored regexes rather than
 * `{ exact: false }`, which matches "Confirm password" for "Password" as well
 * and needs a `.first()` to disambiguate: a locator that depends on document
 * order is a locator that breaks when a field moves.
 */
const field = (page: Page, label: RegExp) => page.getByLabel(label);

const BUSINESS_NAME = /^Business name/;
const YOUR_NAME = /^Your name/;
const EMAIL = /^Email/;
const PASSWORD_FIELD = /^Password/;
const CONFIRMATION = /^Confirm password/;

async function fillEverything(page: Page, email: string, confirmation = PASSWORD): Promise<void> {
    await field(page, BUSINESS_NAME).fill('Willow Street Grooming');
    await page.getByRole('radio', { name: /Dog grooming/ }).check();
    await field(page, YOUR_NAME).fill('Maya Chen');
    await field(page, EMAIL).fill(email);
    await field(page, PASSWORD_FIELD).fill(PASSWORD);
    await field(page, CONFIRMATION).fill(confirmation);
}

/** Every field, read back. The assertion every failure case ends on. */
async function expectNothingLost(page: Page, email: string, confirmation = PASSWORD): Promise<void> {
    await expect(field(page, BUSINESS_NAME)).toHaveValue('Willow Street Grooming');
    await expect(page.getByRole('radio', { name: /Dog grooming/ })).toBeChecked();
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

        /*
         * At this width the progress is the rail in the quiet column: the whole
         * list, named, with mono numerals and the row you are on washed in the
         * accent. It is a list rather than a meter, so it has no `progressbar`
         * role to read — what it says is said in words, and the words are
         * `SetupSteps::all()`.
         */
        // Scoped to the `<ol>`, because the compact meter is still in the DOM
        // at this width — hidden by CSS — and names the current step too.
        const rail = page.getByRole('list');
        await expect(page.getByText('Setting up')).toBeVisible();
        await expect(rail.getByText('Your account')).toBeVisible();
        await expect(rail.getByText('Booking link')).toBeVisible();
        await expect(rail.getByRole('listitem')).toHaveCount(6);

        await settled(page);
        await expect(page).toHaveScreenshot('register-1280.png', { fullPage: true });

        /*
         * At 375 the rail is gone and the compact meter carries the same two
         * facts in 20px. Six steps, this being the first — the count is the
         * whole flow rather than the signed-in part of it, which is the fact
         * `SetupSteps` exists to keep honest.
         */
        await at(page, 375, 1200);
        const progress = page.getByRole('progressbar');
        await expect(progress).toHaveAttribute('aria-valuemax', '6');
        await expect(progress).toHaveAttribute('aria-valuenow', '1');

        await settled(page);
        await expect(page).toHaveScreenshot('register-375.png', { fullPage: true });

        /*
         * Straight into step two of the same flow, so the two screenshots sit
         * next to each other in the same directory and can be read as a pair:
         * same hairlines, same 6px controls, same clay button in the same
         * place, same mono numerals.
         */
        await at(page, 1280, 1100);
        await fillEverything(page, address('pair'));
        await page.getByRole('button', { name: 'Create the account' }).click();

        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();

        // The prefill, in a browser: step one asked for these two and step two
        // opens holding both.
        await expect(field(page, BUSINESS_NAME)).toHaveValue('Willow Street Grooming');
        await expect(page.getByRole('radio', { name: /Dog grooming/ })).toBeChecked();

        await settled(page);
        await expect(page).toHaveScreenshot('register-2-basics-1280.png', { fullPage: true });
    });

    test('the confirmation not matching is said before anything is sent', async ({ page }) => {
        await at(page, 1280, 1100);
        await page.goto('/register');

        const email = address('mismatch');
        await fillEverything(page, email, 'correct-horse-batery');

        // Said on the keystroke it diverged, with no request made.
        await expect(page.getByText('Those two passwords do not match.')).toBeVisible();

        await page.getByRole('button', { name: 'Create the account' }).click();

        // Still on the form, still holding everything, no error page.
        await expect(page).toHaveURL(/\/register$/);
        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();
        await expectNothingLost(page, email, 'correct-horse-batery');

        await settled(page);
        await expect(page).toHaveScreenshot('register-mismatch-1280.png', { fullPage: true });

        // And it clears itself the moment the two agree.
        await field(page, CONFIRMATION).fill(PASSWORD);
        await expect(page.getByText('Those two passwords do not match.')).toBeHidden();
    });

    test('an address that is already registered is answered with the door', async ({ page }) => {
        const email = address('dupe');

        // Register it once, sign out, and come back to the form with it.
        await at(page, 1280, 1100);
        await page.goto('/register');
        await fillEverything(page, email);
        await page.getByRole('button', { name: 'Create the account' }).click();
        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();

        await page.context().clearCookies();
        await page.goto('/register');
        await fillEverything(page, email);
        await page.getByRole('button', { name: 'Create the account' }).click();

        const message = page.getByText('An account with this email already exists');
        await expect(message).toBeVisible();

        // A real link, to the real door.
        const door = message.getByRole('link', { name: 'sign in instead' });
        await expect(door).toHaveAttribute('href', /\/login$/);

        // The message is the email field's own, not a summary at the top.
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

        // The network, cut. Not a 500 — a request that gets no answer at all,
        // which is the case that used to leave the page silent and still.
        await page.route('**/register', (route) => route.abort('failed'));

        await page.getByRole('button', { name: 'Create the account' }).click();

        await expect(page.getByText('Not sent')).toBeVisible();
        await expect(page.getByText('Everything you typed is still here')).toBeVisible();

        // No Inertia error modal, no Laravel error page: still the form.
        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();
        await expectNothingLost(page, email);

        // The button is available again rather than stuck mid-flight.
        const button = page.getByRole('button', { name: 'Create the account' });
        await expect(button).toBeEnabled();

        await settled(page);
        await expect(page).toHaveScreenshot('register-not-sent-1280.png', { fullPage: true });

        // Dismissible, and then the retry is simply pressing the button again.
        await page.getByRole('button', { name: 'Dismiss' }).click();
        await expect(page.getByText('Not sent')).toBeHidden();

        await page.unroute('**/register');
        await page.getByRole('button', { name: 'Create the account' }).click();
        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();
    });

    test('the tenth failure locks the form with a sentence rather than a 429 page', async ({ page }) => {
        const email = address('locked');

        /*
         * The lockout counts *server-side* failures, so the attempts have to be
         * ones the browser is happy with: an address that is already
         * registered. Ten of those, then the eleventh.
         *
         * The e2e server runs on a frozen clock, which means the limiter's
         * window never advances — see the note in `scripts/e2e-setup.sh`. That
         * is why this uses an address of its own: once locked, this key stays
         * locked for the rest of the run.
         */
        await at(page, 1280, 1100);
        await page.goto('/register');
        await fillEverything(page, email);
        await page.getByRole('button', { name: 'Create the account' }).click();
        await expect(page.getByRole('heading', { name: 'Tell us about the business' })).toBeVisible();

        await page.context().clearCookies();

        for (let attempt = 0; attempt < 10; attempt++) {
            await page.goto('/register');
            await fillEverything(page, email);
            await page.getByRole('button', { name: 'Create the account' }).click();
            await expect(page.getByText('An account with this email already exists')).toBeVisible();
        }

        await page.getByRole('button', { name: 'Create the account' }).click();

        // A sentence with the wait in it, above the form, in the product's own
        // type — not Laravel's "429 Too Many Requests" page.
        await expect(page.getByText(/^Too many attempts\. Try again in \d+ (seconds|minutes)\.$/)).toBeVisible();
        await expect(page.getByRole('heading', { name: 'Set up your business' })).toBeVisible();

        // The lockout is the whole answer: the email field is no longer the one
        // being told off, and nothing has been cleared.
        await expect(field(page, EMAIL)).toHaveJSProperty('ariaInvalid', null);
        await expectNothingLost(page, email);

        await settled(page);
        await expect(page).toHaveScreenshot('register-locked-1280.png', { fullPage: true });
    });
});
