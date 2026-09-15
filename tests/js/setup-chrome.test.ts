import SetupChrome from '@/Components/SetupChrome.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const STEPS = [
    { key: 'account', label: 'Your account' },
    { key: 'basics', label: 'Business basics' },
    { key: 'business', label: 'Business details' },
    { key: 'services', label: 'First service' },
    { key: 'link', label: 'Booking link' },
];

const mountAt = (current: string) =>
    mount(SetupChrome, {
        props: { steps: STEPS, current },
        global: { stubs: { AppLogo: true } },
    });

describe('the progress bar', () => {
    it('is a progressbar, not five decorative bricks', () => {
        const bar = mountAt('account').find('[role="progressbar"]');

        expect(bar.exists()).toBe(true);
        expect(bar.attributes('aria-valuemin')).toBe('1');
        expect(bar.attributes('aria-valuemax')).toBe('5');
        expect(bar.attributes('aria-valuenow')).toBe('1');
    });

    it('says where you are in words, so it is not announced as a bare number', () => {
        const bar = mountAt('account').find('[role="progressbar"]');

        expect(bar.attributes('aria-valuetext')).toBe('Step 1 of 5, Your account');
    });

    it('tracks the step it is given', () => {
        const bar = mountAt('services').find('[role="progressbar"]');

        expect(bar.attributes('aria-valuenow')).toBe('4');
        expect(bar.attributes('aria-valuetext')).toBe('Step 4 of 5, First service');
    });

    it('fills one segment per step reached, and no more', () => {
        const filled = mountAt('business')
            .find('[role="progressbar"]')
            .findAll('div')
            .filter((segment) => segment.classes().includes('bg-accent'));

        expect(filled).toHaveLength(3);
    });
});

describe('the step counter', () => {
    it('reads the same in the header and the footer', () => {
        const counters = mountAt('basics')
            .findAll('span')
            .filter((el) => el.text().startsWith('STEP '));

        expect(counters).toHaveLength(2);
        expect(counters[0].text()).toBe('STEP 2 OF 5');
        expect(counters[1].text()).toBe('STEP 2 OF 5');
    });

    it('names the section beside the logomark', () => {
        expect(mountAt('link').find('.eyebrow').text()).toBe('DiaryDesk setup · Booking link');
    });
});
