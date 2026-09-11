import LoyaltyPage from '@/Pages/Settings/Loyalty.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { forms, resetForms, router, setPageProps } from './setup';

/**
 * The loyalty settings tab.
 *
 * Three things on this screen are logic rather than markup, and all three are
 * the kind that look right in a screenshot and are wrong in use: the preview
 * that has to follow the form rather than the saved record, the counter that
 * has to warn before the server refuses, and the guard that has to stop a
 * half-typed scheme leaving with the tab.
 */
const props = (overrides: Record<string, unknown> = {}) => ({
    loyalty: {
        enabled: true,
        name: 'Groom card',
        sessions_required: 5,
        reward: 'The next groom is free',
        eligible_service_id: null,
        auto_stamp: true,
        auto_enrol: true,
        auto_apply_reward: true,
        show_visit_date: true,
        enrolled: 3,
    },
    services: [
        { value: 1, label: 'Full groom' },
        { value: 2, label: 'Puppy trim' },
    ],
    limits: {
        min_visits: 2,
        max_visits: 50,
        max_reward_length: 120,
        default_name: 'Loyalty card',
        default_visits: 5,
    },
    progress: { '4': 2, '5': 1 },
    preview: { visit_dates: ['2026-03-10', '2026-02-10', '2026-01-13'] },
    ...overrides,
});

const mountPage = (overrides: Record<string, unknown> = {}) =>
    mount(LoyaltyPage, {
        props: props(overrides),
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                SettingsNav: true,
                Head: true,
                PageHeader: true,
            },
        },
    });

beforeEach(() => {
    resetForms();
    setPageProps({ vertical: { appointment_singular: 'appointment' } });
    router.on.mockImplementation(() => () => {});
});

describe('the live preview', () => {
    it('draws one impression per visit the form asks for, not per visit that was saved', async () => {
        const page = mountPage();

        expect(page.findAll('svg')).toHaveLength(5);

        forms[0].sessions_required = 8;
        await page.vm.$nextTick();

        expect(page.findAll('svg')).toHaveLength(8);
    });

    it('reaches both the part-way card and the full one', async () => {
        const page = mountPage();

        expect(page.text()).toContain('3 of 5 stamps');
        expect(page.text()).not.toContain('Free session ready');

        await page.find('button').trigger('click');

        expect(page.text()).toContain('Free session ready');
        expect(page.text()).toContain('card stamped out');
    });

    /*
     * The whole point of the 4a treatment: the date is inside the impression.
     * Switching it off has to leave the stamp there and take only the date.
     */
    it('drops the visit date from the impression when that switch is off', async () => {
        const page = mountPage();

        expect(page.text()).toContain('10 Mar');

        forms[0].show_visit_date = false;
        await page.vm.$nextTick();

        expect(page.text()).not.toContain('10 Mar');
        expect(page.findAll('svg')).toHaveLength(5);
    });

    it('follows the card name as it is typed', async () => {
        const page = mountPage();

        forms[0].name = 'Clip club';
        await page.vm.$nextTick();

        expect(page.text()).toContain('Clip club');
    });
});

describe('the reward counter', () => {
    it('counts what has been typed rather than cutting it off', async () => {
        const page = mountPage();

        expect(page.text()).toContain('22 of 120 characters');

        forms[0].reward = 'a'.repeat(130);
        await page.vm.$nextTick();

        expect(page.text()).toContain('130 of 120 characters');
        expect(page.text()).toContain('That reward is too long to save');
        expect(forms[0].reward).toHaveLength(130);
    });

    it('blocks the save while the reward is over length', async () => {
        const page = mountPage();

        forms[0].reward = 'a'.repeat(130);
        await page.vm.$nextTick();

        const save = page.findAll('button').find((button) => button.text() === 'Save');

        expect(save?.attributes('disabled')).toBeDefined();
    });
});

describe('a shorter card', () => {
    it('warns how many customers a smaller count would complete', async () => {
        const page = mountPage();

        expect(page.text()).not.toContain('A shorter card completes people straight away');

        forms[0].sessions_required = 4;
        await page.vm.$nextTick();

        expect(page.text()).toContain('A shorter card completes people straight away');
        expect(page.text()).toContain('3');
    });

    it('says nothing when the count goes up', async () => {
        const page = mountPage();

        forms[0].sessions_required = 9;
        await page.vm.$nextTick();

        expect(page.text()).not.toContain('A shorter card completes people straight away');
    });
});

describe('switching the scheme off', () => {
    it('asks first, and explains that cards are paused rather than deleted', async () => {
        const page = mountPage();

        const toggle = page.find('[role="switch"]');
        await toggle.trigger('click');

        expect(forms[0].enabled).toBe(true);
        expect(page.text()).toContain('Switch the loyalty card off?');
        expect(page.text()).toContain('paused, not deleted');
    });

    it('switches off once that is confirmed', async () => {
        const page = mountPage();

        await page.find('[role="switch"]').trigger('click');
        const confirm = page.findAll('button').find((button) => button.text() === 'Switch it off');
        await confirm?.trigger('click');

        expect(forms[0].enabled).toBe(false);
    });

    it('does not ask when nobody has a card yet', async () => {
        const page = mountPage({ loyalty: { ...props().loyalty, enrolled: 0 } });

        await page.find('[role="switch"]').trigger('click');

        expect(forms[0].enabled).toBe(false);
        expect(page.text()).not.toContain('Switch the loyalty card off?');
    });
});

describe('the services select', () => {
    it('offers all services first, as a choice rather than a placeholder', () => {
        const page = mountPage();
        const options = page.findAll('option');

        expect(options[0].text()).toBe('All services');
        expect(options[0].attributes('value')).toBe('');
        expect(options).toHaveLength(3);
    });

    it('offers only all services, and where to add some, when there are none', () => {
        const page = mountPage({ services: [] });

        expect(page.findAll('option')).toHaveLength(1);
        expect(page.text()).toContain('Add services to scope this to one');
    });
});

describe('the unsaved-changes guard', () => {
    it('registers a listener that cancels a navigation while the form is dirty', async () => {
        const handlers: Record<string, (event: unknown) => unknown> = {};
        router.on.mockImplementation((event: string, handler: (event: unknown) => unknown) => {
            handlers[event] = handler;

            return () => {};
        });

        const page = mountPage();

        forms[0].isDirty = true;

        const cancelled = handlers.before({
            detail: { visit: { method: 'get', url: new URL('http://localhost/settings') } },
        });

        expect(cancelled).toBe(false);

        await page.vm.$nextTick();

        expect(page.text()).toContain('Leave without saving?');
    });

    it('lets a clean form leave without asking', () => {
        const handlers: Record<string, (event: unknown) => unknown> = {};
        router.on.mockImplementation((event: string, handler: (event: unknown) => unknown) => {
            handlers[event] = handler;

            return () => {};
        });

        const page = mountPage();

        forms[0].isDirty = false;

        const cancelled = handlers.before({
            detail: { visit: { method: 'get', url: new URL('http://localhost/settings') } },
        });

        expect(cancelled).toBeUndefined();
        expect(page.text()).not.toContain('Leave without saving?');
    });

    it('leaves once that is confirmed', async () => {
        const handlers: Record<string, (event: unknown) => unknown> = {};
        router.on.mockImplementation((event: string, handler: (event: unknown) => unknown) => {
            handlers[event] = handler;

            return () => {};
        });

        const page = mountPage();
        forms[0].isDirty = true;

        handlers.before({ detail: { visit: { method: 'get', url: new URL('http://localhost/settings') } } });
        await page.vm.$nextTick();

        const leave = page.findAll('button').find((button) => button.text() === 'Leave');
        await leave?.trigger('click');

        expect(router.visit).toHaveBeenCalledWith('http://localhost/settings', { method: 'get' });
    });
});

describe('saving', () => {
    it('shows a banner when the request never reached the server, and no field errors', async () => {
        const page = mountPage();

        forms[0].patch.mockImplementation((_url: string, options: { onError: (e: unknown) => void }) => {
            options.onError({});
        });

        await page.find('form').trigger('submit');

        expect(page.text()).toContain('That did not reach us');
    });

    it('shows no banner when the server answered with field errors', async () => {
        const page = mountPage();

        forms[0].patch.mockImplementation((_url: string, options: { onError: (e: unknown) => void }) => {
            options.onError({ reward: 'Say what they get, in a few words.' });
        });

        await page.find('form').trigger('submit');

        expect(page.text()).not.toContain('That did not reach us');
    });
});

describe('the stepper', () => {
    it('will not go below the configured floor', async () => {
        const page = mountPage();
        forms[0].sessions_required = 2;
        await page.vm.$nextTick();

        const minus = page.findAll('button').find((button) => button.text() === '−');
        await minus?.trigger('click');

        expect(forms[0].sessions_required).toBe(2);
    });

    it('will not go above the configured ceiling', async () => {
        const page = mountPage();
        forms[0].sessions_required = 50;
        await page.vm.$nextTick();

        const plus = page.findAll('button').find((button) => button.text() === '+');
        await plus?.trigger('click');

        expect(forms[0].sessions_required).toBe(50);
    });

    it('steps by one otherwise', async () => {
        const page = mountPage();

        const plus = page.findAll('button').find((button) => button.text() === '+');
        await plus?.trigger('click');

        expect(forms[0].sessions_required).toBe(6);
    });
});
