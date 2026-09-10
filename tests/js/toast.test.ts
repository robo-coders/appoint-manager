import Banner from '@/Components/ui/Banner.vue';
import Toast from '@/Components/ui/Toast.vue';
import ToastContainer from '@/Components/ui/ToastContainer.vue';
import {
    clearToasts,
    configureToasts,
    DEFAULT_TOAST_DURATION_MS,
    dismissToast,
    toast,
    useToasts,
} from '@/lib/toast';
import { mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const realTeleport = { global: { stubs: { Teleport: false } } } as const;

const items = () => useToasts().items;
const messages = () => items().map((item) => item.message);
const tones = () => items().map((item) => item.tone);

const container = () => document.body.querySelector('[data-testid="toast-container"]') as HTMLElement | null;
const rendered = () => Array.from(document.body.querySelectorAll('[data-testid="toast"]'));

beforeEach(() => {
    clearToasts();
    configureToasts(DEFAULT_TOAST_DURATION_MS);
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    clearToasts();
    document.body.innerHTML = '';
});

describe('the store', () => {
    it('fires a success and clears it after the configured duration', () => {
        toast.success('Note saved');

        expect(messages()).toEqual(['Note saved']);
        expect(tones()).toEqual(['success']);

        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS - 1);
        expect(items()).toHaveLength(1);

        vi.advanceTimersByTime(1);
        expect(items()).toHaveLength(0);
    });

    it('takes the duration from the server-shared config', () => {
        configureToasts(9000);
        toast.success('Hours saved');

        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS);
        expect(items()).toHaveLength(1);

        vi.advanceTimersByTime(9000 - DEFAULT_TOAST_DURATION_MS);
        expect(items()).toHaveLength(0);
    });

    it('ignores a duration that is not a usable number', () => {
        configureToasts(0);
        configureToasts(-1);
        configureToasts(Number.NaN);
        configureToasts(null);

        toast.success('Saved');
        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS);

        expect(items()).toHaveLength(0);
    });

    it('clears an error that has nothing to act on', () => {
        toast.error('Could not reach Stripe');

        expect(tones()).toEqual(['error']);

        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS);
        expect(items()).toHaveLength(0);
    });

    it('never auto-dismisses an error that carries an action', () => {
        const onAction = vi.fn();
        toast.error('Refund did not go through', { actionLabel: 'Try again', onAction });

        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS * 10);

        expect(items()).toHaveLength(1);
        expect(items()[0].action?.label).toBe('Try again');

        items()[0].action?.run();
        expect(onAction).toHaveBeenCalledTimes(1);
    });

    it('treats an action label with no handler as no action at all', () => {
        toast.error('Something went wrong', { actionLabel: 'Try again' });

        expect(items()[0].action).toBeUndefined();
    });

    it('only ever produces two tones', () => {
        toast.success('One');
        toast.error('Two');
        toast.error('Three', { actionLabel: 'Retry', onAction: () => {} });

        expect(new Set(tones())).toEqual(new Set(['success', 'error']));
    });

    it('refuses an empty message and warns while developing', () => {
        const warn = vi.spyOn(console, 'warn').mockImplementation(() => {});

        expect(toast.success('')).toBeNull();
        expect(toast.success('   ')).toBeNull();
        expect(toast.error('')).toBeNull();

        expect(items()).toHaveLength(0);
        expect(warn).toHaveBeenCalled();
    });

    it('dismisses one toast without touching the others, and cancels only its timer', () => {
        const first = toast.success('First');
        toast.success('Second');

        dismissToast(first as number);

        expect(messages()).toEqual(['Second']);

        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS);
        expect(items()).toHaveLength(0);
    });

    it('keeps every message when several failures land at once', () => {
        toast.error('Hazel — no number on file');
        toast.error('Alfie — the text bounced');
        toast.error('Willow — no number on file');

        expect(items()).toHaveLength(3);
        expect(messages()).toEqual([
            'Hazel — no number on file',
            'Alfie — the text bounced',
            'Willow — no number on file',
        ]);
    });
});

describe('Toast', () => {
    const mountToast = (item: Parameters<typeof Toast>[0] extends never ? never : Record<string, unknown>) =>
        mount(Toast, { props: { item } });

    it('fills with ink for a confirmation', () => {
        const wrapper = mountToast({ id: 1, message: 'Saved', tone: 'success' });

        expect(wrapper.get('[data-testid="toast"]').classes()).toContain('bg-ink');
        expect(wrapper.get('[data-testid="toast"]').classes()).toContain('text-paper');
        expect(wrapper.get('[data-testid="toast"]').classes()).not.toContain('bg-accent');
    });

    it('fills with the accent only for a failure', () => {
        const wrapper = mountToast({ id: 1, message: 'Payment failed', tone: 'error' });

        expect(wrapper.get('[data-testid="toast"]').classes()).toContain('bg-accent');
        expect(wrapper.get('[data-testid="toast"]').classes()).toContain('text-paper');
        expect(wrapper.get('[data-testid="toast"]').classes()).not.toContain('bg-ink');
    });

    it('carries no border and no shadow, whatever the tone', () => {
        for (const tone of ['success', 'error'] as const) {
            const classes = mountToast({ id: 1, message: 'Message', tone }).get('[data-testid="toast"]').classes();

            expect(classes.some((name) => name.startsWith('border'))).toBe(false);
            expect(classes.some((name) => name.startsWith('shadow'))).toBe(false);
            expect(classes).toContain('rounded');
        }
    });

    it('announces a failure assertively and a confirmation politely', () => {
        expect(
            mountToast({ id: 1, message: 'Saved', tone: 'success' }).get('[data-testid="toast"]').attributes('role'),
        ).toBe('status');
        expect(
            mountToast({ id: 1, message: 'Failed', tone: 'error' }).get('[data-testid="toast"]').attributes('role'),
        ).toBe('alert');
    });

    it('renders an action as underlined text in the same foreground', () => {
        const run = vi.fn();
        const wrapper = mountToast({
            id: 1,
            message: 'Refund did not process',
            tone: 'error',
            action: { label: 'Retry', run },
        });

        const action = wrapper.get('[data-testid="toast-action"]');

        expect(action.text()).toBe('Retry');
        expect(action.classes()).toContain('underline');
        expect(action.classes()).toContain('text-paper');

        action.trigger('click');

        expect(run).toHaveBeenCalledTimes(1);
        expect(wrapper.emitted('dismiss')).toHaveLength(1);
    });

    it('offers no action control when there is nothing to do', () => {
        const wrapper = mountToast({ id: 1, message: 'Saved', tone: 'success' });

        expect(wrapper.find('[data-testid="toast-action"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="toast-dismiss"]').exists()).toBe(true);
    });

    it('wraps a very long message rather than letting it run out of the card', () => {
        const wrapper = mountToast({
            id: 1,
            message: 'A'.repeat(400),
            tone: 'error',
        });

        const message = wrapper.get('p');

        expect(message.classes()).toContain('break-words');
        expect(message.classes()).toContain('min-w-0');
    });
});

describe('ToastContainer', () => {
    it('stacks several toasts, oldest first, each with its own dismiss', async () => {
        const wrapper = mount(ToastContainer, { attachTo: document.body, ...realTeleport });

        toast.success('First');
        toast.success('Second');
        toast.error('Third');
        await wrapper.vm.$nextTick();

        expect(rendered().map((node) => node.querySelector('p')?.textContent)).toEqual([
            'First',
            'Second',
            'Third',
        ]);
        expect(rendered().map((node) => node.getAttribute('data-tone'))).toEqual([
            'success',
            'success',
            'error',
        ]);

        (rendered()[1].querySelector('[data-testid="toast-dismiss"]') as HTMLElement).click();
        await wrapper.vm.$nextTick();

        expect(rendered().map((node) => node.querySelector('p')?.textContent)).toEqual(['First', 'Third']);

        wrapper.unmount();
    });

    it('times each toast independently', async () => {
        const wrapper = mount(ToastContainer, { attachTo: document.body, ...realTeleport });

        toast.success('First');
        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS - 500);
        toast.success('Second');

        vi.advanceTimersByTime(500);
        await wrapper.vm.$nextTick();

        expect(rendered().map((node) => node.querySelector('p')?.textContent)).toEqual(['Second']);

        vi.advanceTimersByTime(DEFAULT_TOAST_DURATION_MS);
        await wrapper.vm.$nextTick();

        expect(rendered()).toHaveLength(0);

        wrapper.unmount();
    });

    it('is a polite live region that does not re-announce the whole stack', () => {
        const wrapper = mount(ToastContainer, { attachTo: document.body, ...realTeleport });

        expect(container()?.getAttribute('aria-live')).toBe('polite');
        expect(container()?.getAttribute('aria-atomic')).toBe('false');

        wrapper.unmount();
    });

    it('keeps a stack that was fired before it mounted, and across a remount', async () => {
        toast.success('Fired while navigating');

        const wrapper = mount(ToastContainer, { attachTo: document.body, ...realTeleport });
        await wrapper.vm.$nextTick();

        expect(rendered().map((node) => node.querySelector('p')?.textContent)).toEqual([
            'Fired while navigating',
        ]);

        wrapper.unmount();

        const remounted = mount(ToastContainer, { attachTo: document.body, ...realTeleport });
        await remounted.vm.$nextTick();

        expect(rendered().map((node) => node.querySelector('p')?.textContent)).toEqual([
            'Fired while navigating',
        ]);

        remounted.unmount();
    });

    it('renders nothing at all when no toast has been fired', () => {
        const wrapper = mount(ToastContainer, { attachTo: document.body, ...realTeleport });

        expect(rendered()).toHaveLength(0);
        expect(container()?.textContent?.trim()).toBe('');

        wrapper.unmount();
    });
});

describe('Banner', () => {
    it('is the same hairline strip the notices already used', () => {
        const wrapper = mount(Banner, { props: { message: 'Trial ends in 9 days.' } });
        const strip = wrapper.get('[data-testid="banner"]');

        expect(strip.text()).toContain('Trial ends in 9 days.');
        expect(strip.classes()).toContain('border-b');
        expect(strip.classes()).toContain('border-b-rule');
        expect(strip.classes()).toContain('text-13');
        expect(strip.classes()).not.toContain('border-l-2');
    });

    it('adds an accent edge for attention, and never a filled band', () => {
        const strip = mount(Banner, { props: { tone: 'attention', message: 'Payment failed.' } }).get(
            '[data-testid="banner"]',
        );

        expect(strip.classes()).toContain('border-l-2');
        expect(strip.classes()).toContain('border-l-accent');
        expect(strip.classes().some((name) => name.startsWith('bg-'))).toBe(false);
    });

    it('reports its tone for either value', () => {
        expect(
            mount(Banner, { props: { message: 'A' } }).get('[data-testid="banner"]').attributes('data-tone'),
        ).toBe('neutral');
        expect(
            mount(Banner, { props: { tone: 'attention', message: 'A' } })
                .get('[data-testid="banner"]')
                .attributes('data-tone'),
        ).toBe('attention');
    });

    it('renders an action as a link after the message', () => {
        const wrapper = mount(Banner, {
            props: {
                message: 'Trial ends in 9 days.',
                actionLabel: 'Add a card',
                actionHref: '/settings/billing',
            },
        });

        const action = wrapper.get('a');

        expect(action.text()).toBe('Add a card');
        expect(action.attributes('href')).toBe('/settings/billing');
        expect(action.classes()).toContain('underline');
    });

    it('offers no action when only half of one is given', () => {
        expect(mount(Banner, { props: { message: 'A', actionLabel: 'Do it' } }).find('a').exists()).toBe(false);
        expect(mount(Banner, { props: { message: 'A', actionHref: '/x' } }).find('a').exists()).toBe(false);
    });

    it('lays the content out in a row only when asked', () => {
        expect(mount(Banner, { props: { message: 'A' } }).get('[data-testid="banner"]').classes()).not.toContain(
            'flex',
        );
        expect(
            mount(Banner, { props: { message: 'A', row: true } }).get('[data-testid="banner"]').classes(),
        ).toContain('flex');
    });

    it('prefers slotted content over the message prop', () => {
        const wrapper = mount(Banner, {
            props: { message: 'Ignored' },
            slots: { default: 'Sandbox tools live here' },
        });

        expect(wrapper.text()).toContain('Sandbox tools live here');
        expect(wrapper.text()).not.toContain('Ignored');
    });

    it('renders itself away for nobody — dismissal is the caller’s to decide', () => {
        const wrapper = mount(Banner, { props: { message: 'You are using a beta preview.' } });

        expect(wrapper.find('[data-testid="banner"]').exists()).toBe(true);
        expect(wrapper.findAll('button')).toHaveLength(0);
    });
});
