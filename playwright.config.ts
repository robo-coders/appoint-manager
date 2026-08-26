import { defineConfig, devices } from '@playwright/test';
import { AUTH_STATE } from './tests/e2e/support';

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
             * laptop for no reason anybody can act on. This is loose enough to
             * survive that and tight enough to catch a clipped label or a row
             * that has started wrapping — which is what these are for.
             */
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
     * Three projects, because the specs have three different needs.
     *
     * `setup` signs in once and saves the session. `operator` reuses it —
     * signing in per spec is nine logins in two minutes against a limiter that
     * allows five, which failed as a navigation timeout that looked like a
     * flaky screenshot.
     *
     * `public` runs signed out, because the booking page is a stranger on a
     * phone and a spec that happens to carry an operator session is not testing
     * that.
     */
    projects: [
        { name: 'setup', testMatch: /auth\.setup\.ts/ },
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
            testMatch: /slot-race\.spec\.ts/,
            use: { ...devices['Desktop Chrome'] },
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
