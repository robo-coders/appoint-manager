import CalendarFeedRow from '@/Components/CalendarFeedRow.vue';
import { clearToasts, useToasts } from '@/lib/toast';
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

const HOST = 'http://localhost/book/ical/paws-and-whiskers/';
const LONG_TOKEN = 'YrK2pQ7xLm4Nv9TzB1CdHf6JsW8aEg0RuO3iXn5Z';
const SHORT_TOKEN = 'abcd';

const urlFor = (token: string) => `${HOST}${token}.ics`;

const mountRow = (overrides: Record<string, unknown> = {}) =>
    mount(CalendarFeedRow, {
        props: {
            label: 'Sam Doherty',
            sublabel: 'Staff',
            url: urlFor(LONG_TOKEN),
            lastPulledAt: '11 minutes ago',
            subscriberName: 'Sam',
            onRegenerate: () => Promise.resolve(),
            ...overrides,
        },
    });

const messages = () => useToasts().items.map((item) => item.message);

const setClipboard = (value: unknown) =>
    Object.defineProperty(navigator, 'clipboard', { value, configurable: true, writable: true });

let originalClipboard: unknown;

beforeEach(() => {
    originalClipboard = (navigator as unknown as { clipboard?: unknown }).clipboard;
    clearToasts();
    vi.useFakeTimers();
});

afterEach(() => {
    vi.useRealTimers();
    setClipboard(originalClipboard);
    delete (document as unknown as { execCommand?: unknown }).execCommand;
});

describe('copying the link', () => {
    it('writes through the clipboard API and says so', async () => {
        const writeText = vi.fn().mockResolvedValue(undefined);
        setClipboard({ writeText });

        const onCopy = vi.fn();
        const wrapper = mountRow({ onCopy });

        await wrapper.get('[data-testid="calendar-feed-copy"]').trigger('click');
        await flushPromises();

        expect(writeText).toHaveBeenCalledWith(urlFor(LONG_TOKEN));
        expect(messages()).toContain('Link copied');
        expect(onCopy).toHaveBeenCalledWith(urlFor(LONG_TOKEN));
        expect(wrapper.get('[data-testid="calendar-feed-copy"]').text()).toBe('Copy');
    });

    it('falls back to a selection copy when the clipboard API is not there', async () => {
        setClipboard(undefined);

        const execCommand = vi.fn().mockReturnValue(true);
        (document as unknown as { execCommand: unknown }).execCommand = execCommand;

        const wrapper = mountRow();

        await wrapper.get('[data-testid="calendar-feed-copy"]').trigger('click');
        await flushPromises();

        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(messages()).toContain('Link copied');
    });

    it('falls back to a selection copy when the clipboard API refuses', async () => {
        setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('denied')) });

        const execCommand = vi.fn().mockReturnValue(true);
        (document as unknown as { execCommand: unknown }).execCommand = execCommand;

        const wrapper = mountRow();

        await wrapper.get('[data-testid="calendar-feed-copy"]').trigger('click');
        await flushPromises();

        expect(execCommand).toHaveBeenCalledWith('copy');
        expect(messages()).toContain('Link copied');
    });

    it('selects the address and names the shortcut when both routes fail', async () => {
        setClipboard({ writeText: vi.fn().mockRejectedValue(new Error('denied')) });
        (document as unknown as { execCommand: unknown }).execCommand = vi.fn().mockReturnValue(false);

        const wrapper = mountRow();
        const field = wrapper.get('input').element as HTMLInputElement;
        const select = vi.spyOn(field, 'select');

        await wrapper.get('[data-testid="calendar-feed-copy"]').trigger('click');
        await flushPromises();

        expect(select).toHaveBeenCalled();
        expect(messages().join(' ')).toContain('Copy failed');
        expect(useToasts().items.at(-1)?.tone).toBe('error');
    });
});

describe('regenerating the link', () => {
    it('does nothing until the consequence has been confirmed', async () => {
        const onRegenerate = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountRow({ onRegenerate });

        expect(wrapper.find('[data-testid="calendar-feed-confirm"]').exists()).toBe(false);

        await wrapper.get('[data-testid="calendar-feed-regenerate"]').trigger('click');

        const confirm = wrapper.get('[data-testid="calendar-feed-confirm"]');

        expect(confirm.text()).toContain('breaks the subscription on every device');
        expect(confirm.text()).toContain('Sam will need to remove the old calendar');
        expect(onRegenerate).not.toHaveBeenCalled();

        await confirm.get('[data-testid="calendar-feed-confirm-regenerate"]').trigger('click');
        await flushPromises();

        expect(onRegenerate).toHaveBeenCalledTimes(1);
        expect(messages()).toContain('Link regenerated — the old link stopped working');
        expect(wrapper.find('[data-testid="calendar-feed-confirm"]').exists()).toBe(false);
    });

    it('keeps the current link when the confirmation is dismissed', async () => {
        const onRegenerate = vi.fn().mockResolvedValue(undefined);
        const wrapper = mountRow({ onRegenerate });

        await wrapper.get('[data-testid="calendar-feed-regenerate"]').trigger('click');
        await wrapper.get('[data-testid="calendar-feed-confirm"]').findAll('button')[1].trigger('click');

        expect(wrapper.find('[data-testid="calendar-feed-confirm"]').exists()).toBe(false);
        expect(onRegenerate).not.toHaveBeenCalled();
    });

    it('fires once when the confirmation is double-clicked', async () => {
        const inFlight: { release?: () => void } = {};
        const onRegenerate = vi.fn(
            () =>
                new Promise<void>((resolve) => {
                    inFlight.release = resolve;
                }),
        );
        const wrapper = mountRow({ onRegenerate });

        await wrapper.get('[data-testid="calendar-feed-regenerate"]').trigger('click');

        const button = wrapper.get('[data-testid="calendar-feed-confirm-regenerate"]').element as HTMLElement;

        button.click();
        button.click();

        await flushPromises();

        expect(onRegenerate).toHaveBeenCalledTimes(1);
        expect(
            wrapper.get('[data-testid="calendar-feed-regenerate"]').attributes('disabled'),
        ).toBeDefined();

        inFlight.release?.();
        await flushPromises();

        expect(
            wrapper.get('[data-testid="calendar-feed-regenerate"]').attributes('disabled'),
        ).toBeUndefined();
    });

    it('reports a failure through an error toast that carries the retry', async () => {
        const onRegenerate = vi.fn().mockRejectedValue(new Error('422'));
        const wrapper = mountRow({ onRegenerate });

        await wrapper.get('[data-testid="calendar-feed-regenerate"]').trigger('click');
        await wrapper.get('[data-testid="calendar-feed-confirm-regenerate"]').trigger('click');
        await flushPromises();

        const failure = useToasts().items.at(-1);

        expect(failure?.tone).toBe('error');
        expect(failure?.message).toContain('the current link is still the live one');
        expect(failure?.action?.label).toBe('Try again');
        expect(messages()).not.toContain('Link regenerated — the old link stopped working');
        expect(wrapper.find('[data-testid="calendar-feed-confirm"]').exists()).toBe(false);

        failure?.action?.run();
        await flushPromises();

        expect(wrapper.find('[data-testid="calendar-feed-confirm"]').exists()).toBe(true);
    });
});

describe('the address itself', () => {
    it('holds the whole address whatever length the token is', () => {
        for (const token of [SHORT_TOKEN, LONG_TOKEN]) {
            const wrapper = mountRow({ url: urlFor(token) });
            const field = wrapper.get('input');

            expect((field.element as HTMLInputElement).value).toBe(urlFor(token));
            expect(field.attributes('readonly')).toBeDefined();
        }
    });

    it('clips the display rather than the value', () => {
        const wrapper = mountRow({ url: urlFor(LONG_TOKEN) });
        const field = wrapper.get('input');

        expect(field.classes()).toContain('truncate');
        expect(field.classes()).toContain('font-mono');
        expect((field.element as HTMLInputElement).value).toHaveLength(urlFor(LONG_TOKEN).length);
    });

    it('says when a feed has never been pulled', () => {
        expect(mountRow().text()).toContain('Last pulled 11 minutes ago');
        expect(mountRow({ lastPulledAt: null }).text()).toContain('Not yet synced');
    });
});
