import { defineConfig, devices } from '@playwright/test';
import { AUTH_STATE, CONSOLE_STATE } from './tests/e2e/support';

/**
 * End-to-end, against a real server and the demo tenant.
 *
 * The unit suite renders components in jsdom; this drives the whole stack. The
 * two are not redundant — the 409 slot race cannot be tested in jsdom because
 * the thing under test is *two browser contexts hitting one row lock*, and a
 * screenshot cannot be taken of a component that has never been laid out.
 *
 * The server is started here rather than assumed. `php artisan serve` is
 * single-threaded, so `workers: 1`: two parallel specs would queue behind each
 * other and time out in ways that look like flakes and are not.
 *
 * Stripe keys are passed as obvious fakes. `AppServiceProvider` refuses to boot
 * without them (AUDIT C1) and the fake gateway is unreachable outside `testing`
 * — so the e2e run uses the real gateway class against keys that will never be
 * called, and no spec here reaches a card.
 */
const PORT = 8129;
const BASE_URL = `http://127.0.0.1:${PORT}`;

/**
 * The instant this whole suite happens at.
 *
 * Exported so a spec freezes the browser clock to the same value the server is
 * frozen to rather than to a literal of its own — the two drifting apart is how
 * "In the chair 31 min" and a masked region stop agreeing.
 */
export const FROZEN_NOW = '2026-08-26T13:34:00+01:00';

export default defineConfig({
    testDir: './tests/e2e',
    outputDir: './tests/e2e/.output',
    snapshotPathTemplate: '{testDir}/__screenshots__/{arg}{ext}',

    fullyParallel: false,
    workers: 1,
    retries: 0,
    reporter: [['list']],

    timeout: 60_000,
    expect: {
        timeout: 10_000,
        toHaveScreenshot: {
            /*
             * Font rasterisation and sub-pixel layout differ enough between
             * machines that a zero-tolerance snapshot fails on somebody else's
             * laptop for no reason anybody can act on. `maxDiffPixelRatio` is
             * loose enough to survive that and tight enough to catch a clipped
             * label or a row that has started wrapping.
             *
             * **`threshold` is 0.03 and that is not a taste decision.** It is
             * the per-pixel colour tolerance, compared in YIQ space as
             * `35215 * threshold²`, and at Playwright's default of 0.2 it
             * ignores anything under a delta of 1408. This palette's two page
             * surfaces — `--paper` #FCFBF9 and `--paper-sunk` #F4F2EE — are a
             * delta of **40.8** apart. So an entire `paper-sunk` panel could
             * appear or disappear and every pixel of it counted as unchanged,
             * which is exactly what happened: the auth layout's quiet column
             * was removed at 768 and `--update-snapshots` wrote nothing,
             * because only the panel's hairline and its text registered at all
             * — about 1.3% of the frame, under `maxDiffPixelRatio`.
             *
             * 0.03 gives a maxDelta of 31.7, which is under 40.8, so a
             * paper/paper-sunk region change now counts. It is the loosest
             * value that does.
             *
             * `--paper` against `--white` is a delta of **8.3** and cannot be
             * caught by any usable threshold at all. Nothing here can police
             * that, so it is not pretended: `expectSurfaces()` in
             * `tests/e2e/support.ts` reads computed background colours and
             * asserts them against the tokens, which is deterministic and does
             * not care what machine it runs on.
             */
            threshold: 0.03,
            maxDiffPixelRatio: 0.02,
            animations: 'disabled',
        },
    },

    use: {
        baseURL: BASE_URL,
        trace: 'retain-on-failure',
        screenshot: 'only-on-failure',
        ...devices['Desktop Chrome'],
    },

    /*
     * Four projects, because the specs have four different needs.
     *
     * `setup` signs in once and saves the session. `operator` reuses it —
     * signing in per spec is nine logins in two minutes against a limiter that
     * allows five, which failed as a navigation timeout that looked like a
     * flaky screenshot.
     *
     * `public` runs signed out, because the booking page is a stranger on a
     * phone and a spec that happens to carry an operator session is not testing
     * that.
     *
     * `console` is the super admin surface. It gets its own setup and its own
     * storage state rather than borrowing the operator's, because the whole
     * point of the surface split is that a session on one surface is not a
     * session on another — and because `admin-login` allows **three** attempts a
     * minute rather than five, so three console specs signing in for themselves
     * are throttled by the third. See `console.setup.ts`.
     */
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
        { name: 'console-setup', testMatch: /console\.setup\.ts/ },
        {
            name: 'operator',
            dependencies: ['setup'],
            testMatch: /(screens|logout)\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], storageState: AUTH_STATE },
        },
        {
            /*
             * Last, and `dependencies` is how that is guaranteed — Playwright
             * has no other ordering between projects.
             *
             * The race spec is the only one that *mutates* the seed: it books
             * out every groomer at the proposed time so the second booker has
             * nowhere to be moved to. Run it first and the booking-page
             * snapshots render the next free slot instead — 10:00 with Marek
             * where the baseline has 09:00 with Rosa — and fail as a 7% pixel
             * diff that looks like a design regression and is not one.
             */
            name: 'public',
            dependencies: ['operator'],
            testMatch: /(slot-race|auth|errors|marketing)\.spec\.ts/,
            use: { ...devices['Desktop Chrome'] },
        },
        {
            name: 'console',
            dependencies: ['console-setup'],
            testMatch: /console\.spec\.ts/,
            use: { ...devices['Desktop Chrome'], storageState: CONSOLE_STATE },
        },
    ],

    /*
     * MySQL, not the local SQLite file, because `lockForUpdate()` is a no-op on
     * SQLite — so the slot race, which is the thing this suite exists to test,
     * cannot be expressed there at all. `scripts/e2e-setup.sh` builds it and
     * says more about why; `npm run test:e2e` runs that first.
     *
     * The setup is *not* in this command. `reuseExistingServer` skips the whole
     * command when a server is already up, and these specs write real bookings —
     * a suite that silently reuses the previous run's data is a suite that
     * passes once and then fails for reasons nobody can reproduce.
     */
    webServer: {
        command: `php artisan serve --host=127.0.0.1 --port=${PORT}`,
        url: `${BASE_URL}/health`,
        reuseExistingServer: !process.env.CI,
        timeout: 180_000,
        env: {
            APP_ENV: 'local',
            /*
             * The same instant `scripts/e2e-setup.sh` seeds with, and the same
             * one the specs hand to `page.clock.setFixedTime`. All three have to
             * agree: the browser clock only freezes what JavaScript reads, and
             * everything these snapshots are actually made of — the dashboard's
             * date, "first available", the shape of the seeded week — is
             * computed in PHP. See the note in the setup script.
             */
            FREEZE_NOW: FROZEN_NOW,
            /*
             * Cookies have to outlive that frozen clock.
             *
             * Laravel stamps the session cookie and `XSRF-TOKEN` with
             * `now() + session.lifetime`, and Symfony then computes `Max-Age`
             * from the *real* `time()` — so an expiry in the real past clamps to
             * `Max-Age=0` and the browser deletes the cookie on receipt. Signing
             * in rendered "419 Page Expired" in an iframe over the login form.
             * Ten years keeps both in the real future. See the setup script.
             */
            SESSION_LIFETIME: '5256000',
            /*
             * Every cross-surface URL in the app is built from `APP_URL` —
             * `home_route()`, `marketing_url()`, `book_url()`. Left at the
             * `.env` value the app redirects to port 8000 after login and the
             * browser follows it off this server entirely. The README says the
             * same thing about `composer dev`.
             */
            APP_URL: BASE_URL,
            DB_CONNECTION: 'mysql',
            DB_HOST: '127.0.0.1',
            DB_PORT: '3306',
            DB_DATABASE: 'appoint_manager_e2e',
            DB_USERNAME: 'root',
            DB_PASSWORD: '',
            STRIPE_KEY: 'pk_test_e2e',
            STRIPE_SECRET: 'sk_test_e2e',
            STRIPE_WEBHOOK_SECRET: 'whsec_e2e',
            STRIPE_BILLING_WEBHOOK_SECRET: 'whsec_billing_e2e',
            STRIPE_PRICE_MONTHLY: 'price_e2e_monthly',
        },
    },
});
