import CustomerSuggestedRule from '@/Components/CustomerSuggestedRule.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { router } from '../../../../../tests/js/setup';

const RULE = {
    no_show_count: 2,
    window_months: 3,
    message: '2 missed visits in 3 months. Take the full amount up front for this customer and the slot is never lost.',
};

const mountBanner = () => mount(CustomerSuggestedRule, { props: { customerId: 7, rule: RULE } });

const banner = (wrapper: ReturnType<typeof mountBanner>) => wrapper.find('[data-testid="suggested-rule"]');

const lastPost = () => {
    const call = router.post.mock.calls.at(-1);

    return { url: String(call?.[0]), options: call?.[2] as Record<string, (errors: Record<string, string>) => void> };
};

beforeEach(() => router.post.mockClear());

describe('the banner', () => {
    it('states the numbers it was generated from', () => {
        expect(banner(mountBanner()).text()).toContain('2 missed visits in 3 months');
    });
});

describe('requiring full payment', () => {
    it('hides the banner at once and posts the override', async () => {
        const wrapper = mountBanner();

        await wrapper.get('[data-testid="suggested-rule-require"]').trigger('click');

        expect(banner(wrapper).exists()).toBe(false);
        expect(lastPost().url).toContain('/customers/require-full-payment/7');
    });

    it('brings the banner back with a reason when the post fails', async () => {
        const wrapper = mountBanner();

        await wrapper.get('[data-testid="suggested-rule-require"]').trigger('click');
        expect(banner(wrapper).exists()).toBe(false);

        lastPost().options.onError({ customer: 'The diary is read-only until billing is up to date.' });
        await wrapper.vm.$nextTick();

        expect(banner(wrapper).exists()).toBe(true);
        expect(wrapper.get('[data-testid="suggested-rule-error"]').text()).toContain('read-only');
    });

    it('uses its own wording when the failure names nothing', async () => {
        const wrapper = mountBanner();

        await wrapper.get('[data-testid="suggested-rule-require"]').trigger('click');

        lastPost().options.onError({});
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[data-testid="suggested-rule-error"]').text()).toContain('The rule is unchanged');
    });
});

describe('dismissing', () => {
    it('hides the banner at once and posts the dismissal', async () => {
        const wrapper = mountBanner();

        await wrapper.get('[data-testid="suggested-rule-dismiss"]').trigger('click');

        expect(banner(wrapper).exists()).toBe(false);
        expect(lastPost().url).toContain('/customers/dismiss-rule/7');
    });

    it('rolls back when the dismissal does not land', async () => {
        const wrapper = mountBanner();

        await wrapper.get('[data-testid="suggested-rule-dismiss"]').trigger('click');

        lastPost().options.onError({});
        await wrapper.vm.$nextTick();

        expect(banner(wrapper).exists()).toBe(true);
    });
});
