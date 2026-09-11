import NavRail, { type NavLink } from '@/Components/ui/NavRail.vue';
import { NAV_ICONS, iconKeyFor, navIconFor } from '@/lib/navIcons';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const links: NavLink[] = [
    { href: 'http://localhost/diary', label: 'Diary', glyph: 'Di' },
    { href: 'http://localhost/bookings', label: 'Bookings', glyph: 'Bk', count: 12 },
    { href: 'http://localhost/customers', label: 'Customers', glyph: 'Cu', count: 348 },
    { href: 'http://localhost/waitlist', label: 'Waitlist', glyph: 'Wl', count: 3 },
    { href: 'http://localhost/overdue', label: 'Overdue', glyph: 'Od', count: 4 },
    { href: 'http://localhost/services', label: 'Services', glyph: 'Sv', count: 9 },
    { href: 'http://localhost/staff', label: 'Staff', glyph: 'St', count: 4 },
    { href: 'http://localhost/hours', label: 'Hours', glyph: 'Hr' },
    { href: 'http://localhost/time-off', label: 'Time off', glyph: 'To' },
    { href: 'http://localhost/overview', label: 'Overview', glyph: 'Ov' },
    { href: 'http://localhost/import', label: 'Import', glyph: 'Im' },
    { href: 'http://localhost/settings', label: 'Settings', glyph: 'Se' },
];

const rail = (props: Record<string, unknown> = {}) =>
    mount(NavRail, {
        props: {
            links,
            isCurrent: (href: string) => href.endsWith('/diary'),
            homeHref: 'http://localhost/diary',
            userName: 'Rosa Adeyemi',
            profileHref: '/profile',
            logoutHref: '/logout',
            ...props,
        },
    });

describe('the icon set', () => {
    it('names an icon for every item in the rail', () => {
        for (const link of links) {
            expect(navIconFor(link.label), `no icon for "${link.label}"`).not.toBeNull();
        }
    });

    it('never gives two items the same icon', () => {
        const used = links.map((link) => navIconFor(link.label));

        expect(new Set(used).size).toBe(links.length);
    });

    it('maps a two-word label to its key', () => {
        expect(iconKeyFor('Time off')).toBe('time-off');
        expect(iconKeyFor('Send log')).toBe('send-log');
    });

    it('covers the console as well as the operator app', () => {
        for (const label of ['Tenants', 'Send log', 'Failures']) {
            expect(navIconFor(label)).not.toBeNull();
        }
    });

    it('is small and fixed, because every entry is a deep import somebody wrote', () => {
        expect(Object.keys(NAV_ICONS)).toHaveLength(16);
    });
});

describe('at 148px', () => {
    it('shows the words, and no icons', () => {
        const wrapper = rail();

        expect(wrapper.text()).toContain('Diary');
        expect(wrapper.text()).toContain('Time off');

        expect(wrapper.findAll('nav svg')).toHaveLength(0);
    });

    it('right-aligns the counts in mono, and omits them where a number means nothing', () => {
        const wrapper = rail();
        const counts = wrapper.findAll('nav .numeral');

        expect(counts.map((c) => c.text())).toEqual(['12', '348', '3', '4', '9', '4']);
    });

    it('marks the current page for assistive tech, not just with a tint', () => {
        const wrapper = rail();
        const current = wrapper.findAll('nav a').filter((a) => a.attributes('aria-current') === 'page');

        expect(current).toHaveLength(1);
        expect(current[0].text()).toContain('Diary');
        expect(current[0].classes()).toContain('bg-accent-tint');

        const marker = current[0].find('span[aria-hidden="true"].bg-accent');

        expect(marker.exists()).toBe(true);
        expect(wrapper.findAll('nav a span[aria-hidden="true"].bg-accent')).toHaveLength(1);
    });
});

describe('at 56px', () => {
    it('draws one icon per item instead of the label', () => {
        const wrapper = rail({ collapsed: true });

        expect(wrapper.findAll('nav a svg')).toHaveLength(links.length);
    });

    it('gives every icon-only link an accessible name and a tooltip', () => {
        const wrapper = rail({ collapsed: true });

        for (const [index, link] of wrapper.findAll('nav a').entries()) {
            expect(link.attributes('aria-label')).toBe(links[index].label);
            expect(link.attributes('title')).toBe(links[index].label);
        }
    });

    it('hides the icons from assistive tech', () => {
        const wrapper = rail({ collapsed: true });

        for (const holder of wrapper.findAll('nav a > span[aria-hidden="true"]')) {
            expect(holder.attributes('aria-hidden')).toBe('true');
        }

        expect(wrapper.findAll('nav a svg').length).toBeGreaterThan(0);
        expect(wrapper.findAll('nav a svg[aria-hidden="false"]')).toHaveLength(0);
    });

    it('names the search control too, since its label is gone as well', () => {
        const wrapper = rail({ collapsed: true });
        const search = wrapper.findAll('button').filter((b) => b.attributes('aria-label') === 'Search');

        expect(search).toHaveLength(1);
        expect(wrapper.find('nav button').exists()).toBe(false);
    });

    it('falls back to letters for an item with no icon', () => {
        const wrapper = mount(NavRail, {
            props: {
                links: [{ href: '/x', label: 'Something New', glyph: 'Sn' }],
                isCurrent: () => false,
                homeHref: '/',
                userName: 'R',
                profileHref: '/p',
                logoutHref: '/l',
                collapsed: true,
            },
        });

        expect(wrapper.find('nav a svg').exists()).toBe(false);
        expect(wrapper.find('nav a').text()).toContain('Sn');
    });
});

describe('the groups', () => {
    const grouped: NavLink[] = [
        { href: '/diary', label: 'Diary', glyph: 'Di', group: 'Day-to-day' },
        { href: '/bookings', label: 'Bookings', glyph: 'Bk', group: 'Day-to-day', count: 12 },
        { href: '/staff', label: 'Staff', glyph: 'St', group: 'Setup' },
        { href: '/settings', label: 'Settings', glyph: 'Se', group: 'Account' },
    ];

    const groupedRail = (props: Record<string, unknown> = {}) =>
        mount(NavRail, {
            props: {
                links: grouped,
                isCurrent: () => false,
                homeHref: '/',
                userName: 'R',
                profileHref: '/p',
                logoutHref: '/l',
                ...props,
            },
        });

    it('draws one heading per group, in the order the caller declared them', () => {
        const headings = groupedRail().findAll('nav .eyebrow');

        expect(headings.map((h) => h.text())).toEqual(['Day-to-day', 'Setup', 'Account']);
    });

    it('sets the headings in small caps rather than shouting them', () => {
        const heading = groupedRail().find('nav .eyebrow');

        expect(heading.text()).toBe('Day-to-day');
        expect(heading.classes()).not.toContain('uppercase');
    });

    it('never gathers items that are not next to each other', () => {
        const wrapper = groupedRail({
            links: [
                { href: '/a', label: 'Diary', group: 'Day-to-day' },
                { href: '/b', label: 'Staff', group: 'Setup' },
                { href: '/c', label: 'Bookings', group: 'Day-to-day' },
            ],
        });

        expect(wrapper.findAll('nav .eyebrow').map((h) => h.text())).toEqual([
            'Day-to-day',
            'Setup',
            'Day-to-day',
        ]);
    });

    it('draws no heading at all for a rail whose items name no group', () => {
        expect(rail().findAll('nav .eyebrow')).toHaveLength(0);
    });

    it('hides the headings with every other word at 56px', () => {
        const headings = groupedRail({ collapsed: true }).findAll('nav .eyebrow');

        for (const heading of headings) expect(heading.classes()).toContain('md:hidden');
    });
});

describe('the drawer', () => {
    it('is the 148px rail, words and all, even while collapsed is set', () => {
        const wrapper = rail({ collapsed: true, drawerOpen: true });

        expect(wrapper.text()).toContain('Customers');
        expect(wrapper.findAll('nav a svg')).toHaveLength(0);
    });
});
