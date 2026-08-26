import { test as setup } from '@playwright/test';
import { AUTH_STATE, signIn } from './support';

/**
 * Sign in once, for the whole run.
 *
 * Every operator spec used to sign in for itself, which is nine logins in about
 * two minutes — and `RateLimiter::for('login')` allows **five a minute** per
 * email and IP. The sixth onwards were throttled, the page stayed on the login
 * form, and the spec failed sixty seconds later with a navigation timeout that
 * looked exactly like a flaky screenshot.
 *
 * The rate limiter is right and the specs were wrong. This signs in once, saves
 * the session, and every other spec starts already authenticated — which is
 * also several seconds faster per test.
 */
setup('authenticate as the salon owner', async ({ page }) => {
    await signIn(page);
    await page.context().storageState({ path: AUTH_STATE });
});
