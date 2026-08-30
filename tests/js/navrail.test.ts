import NavRail, { type NavLink } from '@/Components/ui/NavRail.vue';
import { NAV_ICONS, iconKeyFor, navIconFor } from '@/lib/navIcons';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

/**
 * The rail, at the widths it has.
 *
 * The 56px version is why these exist. Deriving its glyph from the first letter
 * gave Services, Staff and Settings all `S` — three items indistinguishable in
 * the one mode where the label is gone — and that was invisible until the two
 * widths were drawn side by side. Icons replace the letters; the same test that
 * caught the letters catches an icon collision.
 */
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

    /*
     * The letter collision, in a different medium. Two rail items sharing one
     * icon is exactly the bug the letters had, and it would be just as invisible
     * — so it is asserted rather than looked at.
     */
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
        // A number that only moves when a person adds a line to `lib/navIcons`.
        expect(Object.keys(NAV_ICONS)).toHaveLength(15);
    });
});

describe('at 148px', () => {
    it('shows the words, and no icons', () => {
        const wrapper = rail();

        expect(wrapper.text()).toContain('Diary');
        expect(wrapper.text()).toContain('Time off');

        /*
         * Text only, deliberately. The mockup draws it that way, the label is
         * already the fastest thing on screen to read, and an icon beside a word
         * it duplicates is decoration with a width — in a rail where the
         * wordmark already had to go to two lines for want of 8px.
         */
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
        expect(current[0].classes()).toContain('bg-ink-tint');
    });
});

describe('at 56px', () => {
    it('draws one icon per item instead of the label', () => {
        const wrapper = rail({ collapsed: true });

        expect(wrapper.findAll('nav a svg')).toHaveLength(links.length);
    });

    /*
     * An icon-only control needs a name and a tooltip, and the icon itself must
     * be hidden — otherwise a screen reader is offered a graphic and no words.
     */
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

        // Every svg is inside one of those.
        expect(wrapper.findAll('nav a svg').length).toBeGreaterThan(0);
        expect(wrapper.findAll('nav a svg[aria-hidden="false"]')).toHaveLength(0);
    });

    it('names the search control too, since its label is gone as well', () => {
        const wrapper = rail({ collapsed: true });
        const search = wrapper.find('nav button');

        expect(search.attributes('aria-label')).toBe('Search');
    });

    /*
     * The fallback the letters left behind. An item the icon map does not name
     * must still draw *something* — a rail of empty boxes is worse than a rail
     * of letters.
     */
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

describe('the drawer', () => {
    it('is the 148px rail, words and all, even while collapsed is set', () => {
        const wrapper = rail({ collapsed: true, drawerOpen: true });

        expect(wrapper.text()).toContain('Customers');
        expect(wrapper.findAll('nav a svg')).toHaveLength(0);
    });
});
