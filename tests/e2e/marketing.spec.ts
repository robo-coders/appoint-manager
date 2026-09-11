import { expect, test, type Page } from '@playwright/test';
import { FROZEN_NOW } from '../../playwright.config';
import { expectSurface } from './support';

const SHOT_PAGES = [
    ['home', '/'],
    ['pricing', '/pricing'],
] as const;

const ALL_PAGES = [
    ['home', '/'],
    ['pricing', '/pricing'],
    ['how-it-works', '/how-it-works'],
    ['dog-grooming', '/dog-grooming'],
    ['about', '/about'],
    ['contact', '/contact'],
    ['privacy', '/privacy'],
    ['terms', '/terms'],
] as const;

const WIDTHS = [375, 768, 1024, 1280, 1440] as const;

async function revealed(page: Page): Promise<void> {
    await page.evaluate(async () => {
        const step = Math.max(1, Math.round(window.innerHeight * 0.8));

        for (let y = 0; y <= document.documentElement.scrollHeight; y += step) {
            window.scrollTo(0, y);
            await new Promise((resolve) => requestAnimationFrame(() => resolve(null)));
        }
    });

    await page.waitForFunction(() => document.querySelectorAll('.dd-r:not(.dd-in)').length === 0);

    await page.evaluate(() => window.scrollTo(0, 0));
}

async function settled(page: Page): Promise<void> {
    await revealed(page);
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

test('no marketing page scrolls sideways at any width', async ({ page }) => {
    for (const [name, path] of ALL_PAGES) {
        for (const width of WIDTHS) {
            await open(page, path, width);

            const offenders = await page.evaluate(() => {
                const vw = window.innerWidth;
                const out: string[] = [];

                const clipped = (el: Element) => {
                    for (let p = el.parentElement; p; p = p.parentElement) {
                        const s = getComputedStyle(p);
                        if (['hidden', 'clip', 'auto', 'scroll'].includes(s.overflowX)) return true;
                    }
                    return false;
                };

                for (const el of document.querySelectorAll('*')) {
                    const r = el.getBoundingClientRect();
                    if (r.width === 0 && r.height === 0) continue;
                    if (r.right > vw + 0.5 || r.left < -0.5) {
                        if (clipped(el)) continue;
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

test('every focusable element on every marketing page shows the token focus ring', async ({ page }) => {
    const counts: Record<string, number> = {};

    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        const focusable =
            'a[href], button:not([disabled]), input:not([type="hidden"]):not([tabindex="-1"]):not([disabled]), '
            + 'select:not([disabled]), textarea:not([disabled]), [tabindex]:not([tabindex="-1"])';

        const total = await page.evaluate((selector) => document.querySelectorAll(selector).length, focusable);

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

    console.log('focus rings per page:', JSON.stringify(counts));
});

test('the skip link appears on focus and reaches the content', async ({ page }) => {
    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        const skip = page.locator('a.skip-link');
        await expect(skip).toHaveAttribute('href', '#main');

        const before = await skip.boundingBox();
        expect(before, `${name}: skip link has no box`).not.toBeNull();
        expect(before!.y + before!.height, `${name}: skip link is visible before focus`).toBeLessThanOrEqual(0);

        await page.keyboard.press('Tab');
        await expect(skip).toBeFocused();

        const after = await skip.boundingBox();
        expect(after!.y, `${name}: skip link did not come back on focus`).toBeGreaterThanOrEqual(0);

        await expect(page.locator('#main')).toHaveCount(1);
    }
});

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

        const durations = await page.evaluate(() => {
            const s = getComputedStyle(document.documentElement);

            return [s.getPropertyValue('--duration').trim(), s.getPropertyValue('--duration-fast').trim()];
        });

        expect(durations, `${name}: motion tokens are not zeroed`).toEqual(['0ms', '0ms']);
    }

    await context.close();
});

test('every marketing page is on the editorial canvas', async ({ page }) => {
    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        await expectSurface(page.locator('body'), 'canvas', `the ${name} page`);
    }

    await open(page, '/dog-grooming', 1280);
    await expectSurface(page.locator('.thread .msg').first(), 'white', 'the waitlist offer message');
    await expectSurface(page.locator('.thread .msg-later').first(), 'canvasSunk', 'the slot-taken message');
});

test('the header and footer are the same component on every page', async ({ page }) => {
    const shapes: Record<string, string> = {};

    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        const shape = await page.evaluate(() => {
            const head = document.querySelector('header')!.getBoundingClientRect();
            const foot = document.querySelector('footer')!;

            return JSON.stringify({
                headerHeight: Math.round(head.height),
                headerLinks: document.querySelectorAll('header a').length,
                footerLinks: foot.querySelectorAll('a').length,
                logoSlot: foot.querySelectorAll('.logo-slot').length,
                logoArt: foot.querySelectorAll('img, svg').length,
            });
        });

        shapes[name] = shape;
    }

    const first = shapes[ALL_PAGES[0][0]];

    for (const [name] of ALL_PAGES) {
        expect(shapes[name], `${name}'s chrome differs from the home page's`).toBe(first);
    }

    expect(JSON.parse(first).logoSlot).toBe(0);
    expect(JSON.parse(first).logoArt).toBe(1);
});

test('the marketing surface is named on the root of every page', async ({ page }) => {
    for (const [name, path] of ALL_PAGES) {
        await open(page, path, 1280);

        await expect(page.locator('body'), name).toHaveAttribute('data-surface', 'marketing');
        await expect(page.locator('body'), name).toHaveAttribute('data-page', /.+/);
    }
});
