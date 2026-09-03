import { expect, type Browser, type Locator, type Page } from '@playwright/test';

/**
 * The surface tokens, as the browser reports them.
 *
 * These exist because a screenshot cannot tell them apart. Playwright compares
 * pixels in YIQ space against `35215 * threshold²`; `--paper` and `--white` are
 * a delta of **8.3**, which is under the maxDelta of every threshold value that
 * still tolerates font rasterisation on another machine. An input could stop
 * being white, a card could become a page, and the snapshot gate would report
 * clean — this is not a hypothetical, the auth layout's whole quiet column
 * vanished at 768 and `--update-snapshots` wrote nothing.
 *
 * So the surfaces are asserted rather than photographed. See
 * `playwright.config.ts` for the arithmetic.
 */
export const SURFACE = {
    paper: 'rgb(252, 251, 249)',
    paperSunk: 'rgb(244, 242, 238)',
    white: 'rgb(255, 255, 255)',
    /*
     * The marketing site is a different palette from the product, on purpose,
     * and it is not `--paper` with a rounding error: `--canvas` is #FBF9F5 and
     * `--paper` is #FCFBF9. They are two surfaces from two token files, and a
     * spec that asserted one against the other would pass by accident.
     */
    canvas: 'rgb(251, 249, 245)',
    canvasSunk: 'rgb(243, 240, 234)',
} as const;

/**
 * Assert that a region is painted on the surface it is supposed to be.
 *
 * `getComputedStyle` rather than a pixel, so it is exact and identical on every
 * machine. Pass the token name; the failure message says which surface was
 * found, which is the thing you want to read at the point it breaks.
 */
export async function expectSurface(
    locator: Locator,
    token: keyof typeof SURFACE,
    what: string,
): Promise<void> {
    const actual = await locator.evaluate((el) => getComputedStyle(el).backgroundColor);
    const found = (Object.keys(SURFACE) as Array<keyof typeof SURFACE>).find((key) => SURFACE[key] === actual);

    expect(
        actual,
        `${what} should be on --${token} but is ${found ? `--${found}` : actual}`,
    ).toBe(SURFACE[token]);
}

/**
 * The demo tenant, and how to reach it.
 *
 * These specs run against `php artisan demo:seed paw` — real data with a real
 * shape, which is the point: a fixture built to make an assertion pass proves
 * only that the fixture was built correctly.
 */
/** Where `auth.setup.ts` leaves the signed-in session for every other spec. */
export const AUTH_STATE = 'tests/e2e/.auth/owner.json';

/** The console's own session, from `console.setup.ts`. See that file for why. */
export const CONSOLE_STATE = 'tests/e2e/.auth/console.json';

/**
 * The console account, from `SuperAdminSeeder`, which `scripts/e2e-setup.sh`
 * runs. It refuses to run outside `local` — the e2e server is `local` — and it
 * creates an account with a known password on purpose.
 */
export const CONSOLE = {
    email: 'admin@gmail.com',
    password: 'admin@1234',
} as const;

export const DEMO = {
    slug: 'paw',
    ownerEmail: 'owner@paw.test',
    ownerPassword: 'password',
} as const;

/** Sign in through the real form and land wherever the app sends us. */
export async function signIn(page: Page): Promise<void> {
    await page.goto('/login');

    /*
     * By type, not by label. `ui/Label` appends a `*` to a required field, so
     * the accessible name is "Password *" and an exact match finds nothing —
     * which is a fine reason to fail a test about labels and a silly reason to
     * fail every test about anything else.
     */
    await page.locator('input[type="email"]').fill(DEMO.ownerEmail);
    await page.locator('input[type="password"]').fill(DEMO.ownerPassword);
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await page.waitForURL(/\/(diary|dashboard)/);
}

/**
 * Open the account menu pinned to the bottom of the rail.
 *
 * Scoped to the sidebar, and found by `aria-haspopup`. Matching a button by the
 * user's name instead reaches the diary's gap buttons — "360 minutes free with
 * Rosa Adeyemi from 09:00. Book it." is a button whose accessible name contains
 * the same words, and a `.last()` on that is a coin flip.
 */
export async function openAccountMenu(page: Page): Promise<void> {
    await page
        .getByRole('complementary', { name: 'Sidebar' })
        .locator('button[aria-haspopup="menu"]')
        .click();
}

/**
 * A page in its own browser context — its own cookies, its own session.
 *
 * The 409 race needs two customers who have never met, which means two
 * contexts. Two tabs in one context share a cookie jar and would be one
 * customer pressing a button twice, which is a different test.
 */
export async function freshContext(browser: Browser): Promise<Page> {
    const context = await browser.newContext();

    return context.newPage();
}

/**
 * Fill in the details the proposal asks for and press Reserve.
 *
 * The demo tenant is a dog groomer, so its vertical makes Breed and Size
 * required. Filling them by their visible labels rather than by index means
 * this keeps working when a vertical adds a field — and fails loudly, on the
 * label, when one is renamed.
 */
export async function fillDetails(page: Page, name: string, email: string): Promise<void> {
    await page.getByRole('button', { name: /^Reserve / }).click();

    await page.getByLabel('Your name').fill(name);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Mobile').fill('07700900000');
    // "Dog name", sentence case: the vertical's own word for its subject is
    // lower case in config/verticals.php because most of its uses are
    // mid-sentence, and it is sentence-cased where it becomes a label. See
    // `lib/copy`.
    await page.getByLabel('Dog name').fill('Bramble');
    await page.getByLabel('Breed').fill('Labrador');
    await page.getByLabel('Size').selectOption('medium');
}

/**
 * Book a slot straight through the API, as setup rather than as the thing under
 * test.
 *
 * Used to leave exactly one groomer free at a time so that a browser race is
 * genuinely a race. Doing that through the UI would be four more page loads and
 * would test the form four more times.
 */
export async function bookViaApi(
    page: Page,
    slug: string,
    body: { service_id: number; starts_at: string; staff_id: number; email: string; name: string },
): Promise<number> {
    /*
     * The CSRF header, by hand. `page.request` shares the context's cookie jar,
     * so `XSRF-TOKEN` is already there — but nothing adds the matching header
     * the way axios does in the app, and without it every write is a 419.
     */
    const cookies = await page.context().cookies();
    const xsrf = cookies.find((cookie) => cookie.name === 'XSRF-TOKEN')?.value ?? '';

    const response = await page.request.post(`/book/${slug}/bookings`, {
        headers: { 'X-XSRF-TOKEN': decodeURIComponent(xsrf) },
        data: {
            service_id: body.service_id,
            starts_at: body.starts_at,
            staff_id: body.staff_id,
            name: body.name,
            email: body.email,
            phone: '07700900123',
            subject_name: 'Bramble',
            subject_attributes: { breed: 'Labrador', size: 'medium' },
        },
    });

    return response.status();
}

/** What the page is proposing, straight out of the props it was mounted with. */
export async function proposalProps(page: Page): Promise<{
    starts_at: string;
    service_id: number;
    staff_id: number;
    staff_ids: number[];
    time: string;
    day_label: string;
}> {
    const json = await page.locator('#booking-props').textContent();

    return JSON.parse(json ?? '{}').suggestion.primary;
}

/** The proposal the booking page is currently offering, as it reads on screen. */
export async function currentProposal(page: Page): Promise<{ day: string; time: string }> {
    const heading = page.locator('h1.text-34').first();
    await expect(heading).toBeVisible();

    const text = (await heading.innerText()).replace(/\s+/g, ' ').trim();
    const time = /(\d{2}:\d{2})/.exec(text)?.[1] ?? '';

    return { day: text.replace(/\s*at\s*\d{2}:\d{2}\s*$/, '').trim(), time };
}
