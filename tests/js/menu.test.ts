import Menu from '@/Components/ui/Menu.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

/**
 * The row-actions menu. It hangs below the trigger when there is room, and
 * above it when the last row of a list would otherwise open into the heading
 * underneath. Same hairline panel either way.
 */
const items = () => [h(MenuItem, () => 'Call'), h(MenuItem, () => 'Snooze two weeks')];

const rect = (top: number, height: number): DOMRect =>
    ({
        x: 0,
        y: top,
        top,
        bottom: top + height,
        left: 0,
        right: 32,
        width: 32,
        height,
        toJSON() {
            return {};
        },
    }) as DOMRect;

const mockRects = (triggerTop: number, panelHeight: number, viewport: number) => {
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: viewport });
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
        if (this.getAttribute('role') === 'menu') {
            return rect(triggerTop + 32, panelHeight);
        }
        if (this.getAttribute('aria-haspopup') === 'menu') {
            return rect(triggerTop, 32);
        }

        return rect(0, 0);
    });
};

afterEach(() => {
    vi.restoreAllMocks();
});

describe('placement', () => {
    it('hangs below the trigger when there is room', async () => {
        mockRects(40, 160, 800);

        const wrapper = mount(Menu, {
            slots: { default: items },
            attachTo: document.body,
        });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="menu"]').classes()).toContain('top-full');
        expect(wrapper.get('[role="menu"]').classes()).not.toContain('bottom-full');

        wrapper.unmount();
    });

    it('opens upward when the trigger is near the bottom of the viewport', async () => {
        mockRects(350, 160, 400);

        const wrapper = mount(Menu, {
            slots: { default: items },
            attachTo: document.body,
        });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        expect(wrapper.get('[role="menu"]').classes()).toContain('bottom-full');
        expect(wrapper.get('[role="menu"]').classes()).not.toContain('top-full');

        wrapper.unmount();
    });
});
