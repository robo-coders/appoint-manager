import { expect, type Browser, type Locator, type Page } from '@playwright/test';

export const SURFACE = {
    paper: 'rgb(252, 251, 249)',
    paperSunk: 'rgb(244, 242, 238)',
    white: 'rgb(255, 255, 255)',
    canvas: 'rgb(251, 249, 245)',
    canvasSunk: 'rgb(243, 240, 234)',
} as const;

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

export const AUTH_STATE = 'tests/e2e/.auth/owner.json';

export const CONSOLE_STATE = 'tests/e2e/.auth/console.json';

export const CONSOLE = {
    name: 'Super Admin',
    email: 'admin@gmail.com',
    password: 'admin@1234',
} as const;

export const DEMO = {
    slug: 'paw',
    ownerEmail: 'owner@paw.test',
    ownerPassword: 'password',
} as const;

export async function signIn(page: Page): Promise<void> {
    await page.goto('/login');

    await page.locator('input[type="email"]').fill(DEMO.ownerEmail);
    await page.locator('input[type="password"]').fill(DEMO.ownerPassword);
    await page.getByRole('button', { name: /log in|sign in/i }).click();
    await page.waitForURL(/\/(diary|dashboard)/);
}

export async function openAccountMenu(page: Page): Promise<void> {
    await page
        .getByRole('complementary', { name: 'Sidebar' })
        .locator('button[aria-haspopup="menu"]')
        .click();
}

export async function freshContext(browser: Browser): Promise<Page> {
    const context = await browser.newContext();

    return context.newPage();
}

export async function fillDetails(page: Page, name: string, email: string): Promise<void> {
    await page.getByRole('button', { name: /^Reserve / }).click();

    await page.getByLabel('Your name').fill(name);
    await page.getByLabel('Email').fill(email);
    await page.getByLabel('Mobile').fill('07700900000');
    await page.getByLabel('Dog name').fill('Bramble');
    await page.getByLabel('Breed').fill('Labrador');
    await page.getByLabel('Size').selectOption('medium');
}

export async function bookViaApi(
    page: Page,
    slug: string,
    body: { service_id: number; starts_at: string; staff_id: number; email: string; name: string },
): Promise<number> {
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

export async function currentProposal(page: Page): Promise<{ day: string; time: string }> {
    const heading = page.locator('h1.text-34').first();
    await expect(heading).toBeVisible();

    const text = (await heading.innerText()).replace(/\s+/g, ' ').trim();
    const time = /(\d{2}:\d{2})/.exec(text)?.[1] ?? '';

    return { day: text.replace(/\s*at\s*\d{2}:\d{2}\s*$/, '').trim(), time };
}
