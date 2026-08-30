import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { expectSurface } from './support';

/**
 * The marketing surface, at the three widths.
 *
 * Signed out, in the `public` project, because everybody who reads these pages
 * is signed out — and because the masthead is asserted elsewhere to be byte
 * identical either way (`MarketingNavTest`), so a spec carrying an operator
 * session would be testing a page no visitor sees.
 *
 * Home and pricing are the two the brief singled out for baselines. The other
 * five pages are checked for the rules that do not need a picture — overflow,
 * focus, the skip link — in the assertions below.
 */
const SHOT_PAGES = [
    ['home', '/'],
    ['pricing', '/pricing'],
] as const;

const ALL_PAGES = [
    ['home', '/'],
    ['pricing', '/pricing'],
    ['dog-grooming', '/dog-grooming'],
    ['about', '/about'],
    ['contact', '/contact'],
    ['privacy', '/privacy'],
    ['terms', '/terms'],
] as const;

const WIDTHS = [375, 768, 1024, 1280, 1440] as const;

async function settled(page: Page): Promise<void> {
    await page.evaluate(() => document.fonts.ready);
    await page.waitForTimeout(250);
}

async function open(page: Page, path: string, width: number): Promise<void> {
    await page.clock.setFixedTime(new Date(FROZEN_NOW));
    await page.setViewportSize({ width, height: width === 375 ? 900 : 1000 });
    await page.goto(path);
    await expect(page.getByRole('heading', { level: 1 })).toBeVisible();
    await settled(page);
}

for (const [name, path] of SHOT_PAGES) {
    for (const width of WIDTHS) {
        test(`marketing ${name} at ${width}`, async ({ page }) => {
            await open(page, path, width);

            await expect(page).toHaveScreenshot(`marketing-${name}-${width}.png`, { fullPage: true });
        });
    }
}

/*
 * Nothing scrolls sideways, at any width, on any page.
 *
 * This is the assertion a screenshot cannot make: `fullPage` captures the
 * document, so an element 40px past the right edge widens the image rather than
 * being clipped out of it, and the picture looks fine.
 */
test('no marketing page scrolls sideways at any width', async ({ page }) => {
    for (const [name, path] of ALL_PAGES) {
        for (const width of WIDTHS) {
            await open(page, path, width);

            const offenders = await page.evaluate(() => {
                const vw = window.innerWidth;
                const out: string[] = [];

                for (const el of document.querySelectorAll('*')) {
                    const r = el.getBoundingClientRect();
                    if (r.width === 0 && r.height === 0) continue;
                    if (r.right > vw + 0.5 || r.left < -0.5) {
                        const cls = typeof el.className === 'string' && el.className.trim() ? `.${el.className.trim().split(/\s+/).join('.')}` : '';
                        out.push(`${el.tagName.toLowerCase()}${cls}`);
                    }
                }

                return { out: out.slice(0, 5), scrollWidth: document.documentElement.scrollWidth, vw };
            });

            expect(
                offenders.scrollWidth,
                `${name} at ${width} scrolls sideways (${offenders.scrollWidth} > ${offenders.vw}); first offenders: ${offenders.out.join(', ')}`,
            ).toBeLessThanOrEqual(offenders.vw);

            expect(offenders.out, `${name} at ${width} has elements past the viewport`).toEqual([]);
        }
    }
});

/*
 * A visible focus ring on every focusable element, walked by keyboard.
 *
 * Not `el.focus()` in a loop: the ring is on `:focus-visible`, and the question
 * is whether somebody tabbing through the page can see where they are. So this
 * presses Tab and reads back what the browser actually painted.
 *
 * The ring is `--focus-ring`, which is the only shadow in the product, so
 * "box-shadow is not none" is a sufficient and exact test — there is nothing
 * else it could be.
 */
test('every focusable element on every marketing page shows the token focus ring', async ({ page }) => {
    const counts: Record<string, number> = {};

    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        const total = await page.evaluate(
            () =>
                document.querySelectorAll('a[href], button, input, select, textarea, [tabindex]:not([tabindex="-1"])')
                    .length,
        );

        let ringed = 0;

        for (let i = 0; i < total; i++) {
            await page.keyboard.press('Tab');

            const state = await page.evaluate(() => {
                const el = document.activeElement;
                if (!el || el === document.body) return null;
                const s = getComputedStyle(el);

                return {
                    tag: el.tagName.toLowerCase(),
                    text: (el.textContent ?? '').trim().slice(0, 40),
                    shadow: s.boxShadow,
                    radius: s.borderRadius,
                };
            });

            if (state === null) break;

            expect(state.shadow, `${name}: "${state.text}" (${state.tag}) has no focus ring`).not.toBe('none');
            ringed++;
        }

        expect(ringed, `${name}: tabbed through ${ringed} of ${total} focusable elements`).toBe(total);
        counts[name] = ringed;
    }

    // Printed so the count per page is in the run output rather than only in a
    // failure message.
    console.log('focus rings per page:', JSON.stringify(counts));
});

/* The skip link is invisible until it is focused, and then it is not. */
test('the skip link appears on focus and reaches the content', async ({ page }) => {
    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        const skip = page.locator('a.skip-link');
        await expect(skip).toHaveAttribute('href', '#main');

        // Parked off screen: its bottom edge is above the top of the viewport.
        const before = await skip.boundingBox();
        expect(before, `${name}: skip link has no box`).not.toBeNull();
        expect(before!.y + before!.height, `${name}: skip link is visible before focus`).toBeLessThanOrEqual(0);

        // First Tab from the top of the document lands on it.
        await page.keyboard.press('Tab');
        await expect(skip).toBeFocused();

        const after = await skip.boundingBox();
        expect(after!.y, `${name}: skip link did not come back on focus`).toBeGreaterThanOrEqual(0);

        // And it goes somewhere.
        await expect(page.locator('#main')).toHaveCount(1);
    }
});

/*
 * Under `prefers-reduced-motion` nothing is running.
 *
 * `tokens.css` zeroes both durations and forces every animation and transition
 * to 0ms, so the correct number of running animations on a marketing page is
 * zero — not "a shorter one".
 */
test('nothing animates under prefers-reduced-motion', async ({ browser }) => {
    const context = await browser.newContext({ reducedMotion: 'reduce' });
    const page = await context.newPage();

    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        const running = await page.evaluate(
            () =>
                document
                    .getAnimations()
                    .filter((a) => a.playState === 'running')
                    .map((a) => (a as unknown as { animationName?: string }).animationName ?? 'unnamed'),
        );

        expect(running, `${name} has running animations under reduced motion`).toEqual([]);

        // And the tokens themselves are zeroed, which is what the templates read.
        const durations = await page.evaluate(() => {
            const s = getComputedStyle(document.documentElement);

            return [s.getPropertyValue('--duration').trim(), s.getPropertyValue('--duration-fast').trim()];
        });

        expect(durations, `${name}: motion tokens are not zeroed`).toEqual(['0ms', '0ms']);
    }

    await context.close();
});

/*
 * The surfaces, asserted rather than photographed — `--paper` and `--white` are
 * a YIQ delta of 8.3 apart and no usable snapshot threshold can tell them
 * apart. See `support.ts`.
 */
test('the marketing surface is paper, and the footer is the sunk step', async ({ page }) => {
    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        await expectSurface(page.locator('body'), 'paper', `the ${name} page`);
        await expectSurface(page.locator('footer'), 'paperSunk', `the ${name} page footer`);
    }

    // The two quoted text messages are the one place this surface uses --white,
    // and the second of the pair is the sunk step. A screenshot cannot see
    // either of them change.
    await open(page, '/', 1280);
    await expectSurface(page.locator('.msg').first(), 'white', 'the waitlist offer message');
    await expectSurface(page.locator('.msg-later').first(), 'paperSunk', 'the slot-taken message');
});

/* The page frame comes from tokens.css, gated on the surface attribute. */
test('the marketing page frame is the promoted tokens', async ({ page }) => {
    await open(page, '/', 1280);

    await expect(page.locator('body')).toHaveAttribute('data-surface', 'marketing');

    const frame = await page.evaluate(() => {
        const s = getComputedStyle(document.body);

        return {
            page: s.getPropertyValue('--page').trim(),
            gutter: s.getPropertyValue('--gutter').trim(),
            arg: s.getPropertyValue('--arg').trim(),
        };
    });

    expect(frame.page).toBe('1152px');
    // 768 and up takes the wider gutter.
    expect(frame.gutter).toBe('32px');
    expect(frame.arg).toBe('19ch');

    await open(page, '/', 375);
    const narrow = await page.evaluate(() => getComputedStyle(document.body).getPropertyValue('--gutter').trim());
    expect(narrow).toBe('16px');
});
