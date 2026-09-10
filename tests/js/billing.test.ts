import BillingPage, { type BillingPayload, type BillingState } from '@/Pages/Settings/Billing/Index.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const payload = (state: BillingState, overrides: Partial<BillingPayload> = {}): BillingPayload => ({
    state,
    plan: {
        name: 'Studio',
        interval: state === 'cancelled_ended' ? null : 'monthly',
        price: '£29.00',
        period: '/ month',
        limits: '3 staff seats · 200 auto-fill texts a month',
        renews_on: '1 Oct 2026',
        yearly_saving: null,
    },
    payment_method: {
        present: state !== 'no_payment_method' && state !== 'trial' && state !== 'trial_ended',
        brand: 'visa',
        last4: '4242',
        exp: '04/28',
    },
    banner:
        state === 'healthy'
            ? null
            : {
                  variant: state,
                  message: `banner:${state}`,
                  action_label: state === 'cancelled_pending' ? null : 'Update payment method',
                  reassurance: state === 'past_due',
              },
    ends_on: state === 'cancelled_pending' ? '1 Oct 2026' : null,
    trial_days: state === 'trial' ? 6 : 0,
    invoices: [],
    csv_url: '/settings/billing/invoices.csv',
    can_charge: true,
    stripe_key: 'pk_test',
    ...overrides,
});

const mountPage = (state: BillingState, overrides: Partial<BillingPayload> = {}) =>
    mount(BillingPage, {
        props: { billing: payload(state, overrides) },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                SettingsNav: true,
                Head: true,
            },
        },
    });

describe('Billing banners', () => {
    it.each([
        ['healthy', null],
        ['past_due', 'past_due'],
        ['unpaid', 'unpaid'],
        ['trial', 'trial'],
        ['trial_ended', 'trial_ended'],
        ['no_payment_method', 'no_payment_method'],
        ['cancelled_pending', 'cancelled_pending'],
        ['cancelled_ended', 'cancelled_ended'],
        ['incomplete', 'incomplete'],
    ] as const)('renders the %s banner variant', (state, variant) => {
        const wrapper = mountPage(state);

        if (variant === null) {
            expect(wrapper.find('[data-testid="billing-banner"]').exists()).toBe(false);

            return;
        }

        const banner = wrapper.find('[data-testid="billing-banner"]');
        expect(banner.exists()).toBe(true);
        expect(banner.attributes('data-variant')).toBe(variant);
        expect(banner.text()).toContain(`banner:${state}`);
    });

    it('shows no payment method copy on the plan card', () => {
        const wrapper = mountPage('no_payment_method', {
            banner: null,
            payment_method: { present: false, brand: null, last4: null, exp: null },
        });

        expect(wrapper.text()).toContain('No payment method on file');
        expect(wrapper.text()).toContain('Add payment method');
    });

    it('replaces the plan card with resubscribe when the plan has ended', () => {
        const wrapper = mountPage('cancelled_ended');

        expect(wrapper.text()).toContain('Resubscribe');
        expect(wrapper.text()).not.toContain('Change plan');
    });
});
