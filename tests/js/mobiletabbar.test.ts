import MobileTabBar from '@/Components/ui/MobileTabBar.vue';
import type { NavLink } from '@/Components/ui/NavRail.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { setPageProps } from './setup';

const links: NavLink[] = [
    { href: 'http://localhost/diary', label: 'Diary', glyph: 'Di' },
    { href: 'http://localhost/bookings', label: 'Bookings', glyph: 'Bk', count: 12 },
    { href: 'http://localhost/waitlist', label: 'Waitlist', glyph: 'Wl', count: 3 },
    { href: 'http://localhost/overdue', label: 'Overdue', glyph: 'Od', count: 4 },
    { href: 'http://localhost/customers', label: 'Customers', glyph: 'Cu', count: 348 },
    { href: 'http://localhost/services', label: 'Services', glyph: 'Sv', count: 9 },
    { href: 'http://localhost/staff', label: 'Staff', glyph: 'St', count: 4 },
    { href: 'http://localhost/hours', label: 'Hours', glyph: 'Hr' },
    { href: 'http://localhost/time-off', label: 'Time off', glyph: 'To' },
    { href: 'http://localhost/overview', label: 'Overview', glyph: 'Ov' },
    { href: 'http://localhost/import', label: 'Import', glyph: 'Im' },
    { href: 'http://localhost/settings', label: 'Settings', glyph: 'Se' },
];

const TABS = ['Bookings', 'Waitlist', 'Overview', 'Staff', 'More'];

const bar = (url = '/bookings') => {
    setPageProps({ __url: url });

    return mount(MobileTabBar, {
        props: { links, userName: 'Rosa Adeyemi', profileHref: '/profile', logoutHref: '/logout' },
        attachTo: document.body,
    });
};

const cells = (wrapper: ReturnType<typeof bar>) => wrapper.find('nav').findAll('a, button');

beforeEach(() => {
    document.body.innerHTML = '';
});

describe('destinations', () => {
    it('renders exactly the five the mockup draws, in order', () => {
        const wrapper = bar();

        expect(cells(wrapper).map((cell) => cell.text().trim())).toEqual(TABS);
    });

    it('offers no sixth way out of the bar', () => {
        expect(cells(bar())).toHaveLength(5);
    });

    it('reads the current page itself rather than taking it as a prop', () => {
        expect(cells(bar('/waitlist'))[1].attributes('aria-current')).toBe('page');
        expect(cells(bar('/staff'))[3].attributes('aria-current')).toBe('page');
    });

    it('marks a nested route under a tab as that tab', () => {
        expect(cells(bar('/bookings/4821'))[0].attributes('aria-current')).toBe('page');
    });

    it('marks the active tab with the accent rule and leaves the rest monochrome', () => {
        const wrapper = bar('/waitlist');
        const [bookings, waitlist] = cells(wrapper);

        expect(waitlist.classes()).toContain('border-t-accent');
        expect(bookings.classes()).toContain('border-t-transparent');
        expect(bookings.classes()).toContain('text-ink-2');
    });

    it('falls back to More for a destination that has no tab of its own', () => {
        const more = cells(bar('/time-off'))[4];

        expect(more.classes()).toContain('border-t-accent');
    });

    it('does not mark More while a real tab is current', () => {
        expect(cells(bar('/bookings'))[4].classes()).toContain('border-t-transparent');
    });
});

describe('the More sheet', () => {
    const openSheet = async (url = '/bookings') => {
        const wrapper = bar(url);
        await cells(wrapper)[4].trigger('click');

        return wrapper;
    };

    const sheet = () => document.body.querySelector('[role="dialog"]');

    it('carries every destination that is not a tab', async () => {
        await openSheet();
        const text = sheet()?.textContent ?? '';

        for (const label of ['Diary', 'Overdue', 'Customers', 'Services', 'Hours', 'Time off', 'Import', 'Settings']) {
            expect(text).toContain(label);
        }
    });

    it('does not repeat a destination that already has a tab', async () => {
        await openSheet();
        const hrefs = [...(sheet()?.querySelectorAll('a') ?? [])].map((node) => node.getAttribute('href'));

        expect(hrefs).not.toContain('http://localhost/bookings');
        expect(hrefs).not.toContain('http://localhost/staff');
    });

    it('reaches search and the account, which are not places in the product', async () => {
        const wrapper = await openSheet();

        expect(sheet()?.textContent).toContain('Rosa Adeyemi');
        expect(sheet()?.textContent).toContain('Search');

        const search = [...(sheet()?.querySelectorAll('button') ?? [])].find(
            (node) => node.textContent?.trim() === 'Search',
        );
        search?.click();
        await wrapper.vm.$nextTick();

        expect(wrapper.emitted('search')).toHaveLength(1);
    });

    it('closes on the explicit close button', async () => {
        const wrapper = await openSheet();
        expect(sheet()).not.toBeNull();

        (sheet()?.querySelector('[aria-label="Close"]') as HTMLElement | null)?.click();
        await wrapper.vm.$nextTick();

        expect(sheet()).toBeNull();
    });

    it('closes on a backdrop tap', async () => {
        const wrapper = await openSheet();

        (document.body.querySelector('.bg-overlay') as HTMLElement | null)?.click();
        await wrapper.vm.$nextTick();

        expect(sheet()).toBeNull();
    });

    it('closes on a swipe down, and stays open for a swipe that is only a tap', async () => {
        const wrapper = await openSheet();
        const panel = sheet() as HTMLElement;

        const swipe = (from: number, to: number) => {
            panel.dispatchEvent(
                new TouchEvent('touchstart', { touches: [{ clientY: from } as Touch], bubbles: true }),
            );
            panel.dispatchEvent(
                new TouchEvent('touchend', { changedTouches: [{ clientY: to } as Touch], bubbles: true }),
            );
        };

        swipe(400, 410);
        await wrapper.vm.$nextTick();
        expect(sheet()).not.toBeNull();

        swipe(400, 500);
        await wrapper.vm.$nextTick();
        expect(sheet()).toBeNull();
    });

    it('closes when a destination inside it is taken', async () => {
        const wrapper = await openSheet();

        (sheet()?.querySelector('a') as HTMLElement | null)?.click();
        await wrapper.vm.$nextTick();

        expect(sheet()).toBeNull();
        expect(wrapper.emitted('navigate')).toHaveLength(1);
    });
});
