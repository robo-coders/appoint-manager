import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { DEMO } from './support';

/**
 * The public booking page tells three different stories, and until phase 16 it
 * told two of them with the same words.
 *
 * A salon that has not finished setting up has no services and nobody with
 * hours; a salon that is busy has both and no free slot. Both arrived at the
 * island as "no proposal", so a business that had never opened was announced to
 * its first customer as "fully booked — leave your number and we will text you
 * the moment something opens up". Nobody was going to text them.
 *
 * Signed out, in the `public` project, because that is who reads this page.
 *
 * Text rather than screenshots on purpose. The claim here is *which words* each
 * state uses, and the snapshot baselines in this suite are only valid against a
 * pristine seed (see `scripts/e2e-setup.sh`) — a new baseline for a state that
 * is about copy would be a pixel gate on a sentence.
 *
 * `bramble-co` and `clover-grooming` come from the console fixtures in the
 * setup script: live booking pages, no services, no hours. They were seeded to
 * give the super-admin list something to sort and they are, by accident, an
 * exact copy of the bug as reported.
 */

const SETUP_INCOMPLETE_SLUG = 'bramble-co';

async function open(page: Page, slug: string): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.goto(`/book/${slug}`);
    await expect(page.getByRole('heading', { level: 1 }).first()).toBeVisible();
}

async function state(page: Page): Promise<string> {
    const json = await page.locator('#booking-props').textContent();

    return JSON.parse(json ?? '{}').suggestion.state;
}

test('a business that has not finished setting up is not called fully booked', async ({ page }) => {
    await open(page, SETUP_INCOMPLETE_SLUG);

    expect(await state(page)).toBe('setup_incomplete');

    const heading = page.getByRole('heading', { level: 1 }).first();
    await expect(heading).toHaveText(/is not taking online bookings yet/);

    const body = page.locator('body');
    await expect(body).toContainText('has not finished setting up online booking');
    await expect(body).not.toContainText('fully booked');
    await expect(body).not.toContainText('nothing free in the diary');

    // The waitlist is the busy-salon answer. It is not offered here.
    await expect(page.getByRole('button', { name: /text me when something opens/i })).toHaveCount(0);
    await expect(page.getByLabel('Mobile')).toHaveCount(0);
});

test('a configured salon still proposes an appointment, unchanged', async ({ page }) => {
    await open(page, DEMO.slug);

    expect(await state(page)).toBe('proposal');

    const heading = page.locator('h1.text-34').first();
    await expect(heading).toBeVisible();
    await expect(heading).toHaveText(/\d{2}:\d{2}/);

    await expect(page.locator('body')).not.toContainText('is not taking online bookings yet');
    await expect(page.getByRole('button', { name: /reserve|request this time/i }).first()).toBeVisible();
});

test('the setup state still names the business and offers a way to reach it', async ({ page }) => {
    await open(page, SETUP_INCOMPLETE_SLUG);

    const body = page.locator('body');
    await expect(body).toContainText('Bramble and Co');
    await expect(body).toContainText(/Message Bramble and Co|Get in touch with Bramble and Co/);
});
