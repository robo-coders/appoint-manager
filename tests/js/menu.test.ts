import Menu from '@/Components/ui/Menu.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { h } from 'vue';

/**
 * The row-actions menu. It hangs below the trigger when there is room, and
 * above it when the last row of a list would otherwise open into the heading
 * underneath. Same hairline panel either way.
 */
const items = () => [h(MenuItem, () => 'Call'), h(MenuItem, () => 'Snooze two weeks')];

const panel = () => document.body.querySelector('[role="menu"]') as HTMLElement | null;

const realTeleport = { global: { stubs: { Teleport: false } } } as const;

const rect = (top: number, height: number, left = 0, width = 32): DOMRect =>
    ({
        x: left,
        y: top,
        top,
        bottom: top + height,
        left,
        right: left + width,
        width,
        height,
        toJSON() {
            return {};
        },
    }) as DOMRect;

const mockRects = (triggerTop: number, panelHeight: number, viewport: number) => {
    Object.defineProperty(window, 'innerHeight', { configurable: true, value: viewport });
    Object.defineProperty(window, 'innerWidth', { configurable: true, value: 1440 });
    vi.spyOn(HTMLElement.prototype, 'getBoundingClientRect').mockImplementation(function (this: HTMLElement) {
        if (this.getAttribute('role') === 'menu') {
            return rect(0, panelHeight, 0, 176);
        }
        if (this.getAttribute('aria-haspopup') === 'menu') {
            return rect(triggerTop, 32, 1200, 32);
        }

        return rect(0, 0);
    });
};

afterEach(() => {
    vi.restoreAllMocks();
    document.body.innerHTML = '';
});

describe('placement', () => {
    it('hangs below the trigger when there is room', async () => {
        mockRects(40, 160, 800);

        const wrapper = mount(Menu, { slots: { default: items }, attachTo: document.body, ...realTeleport });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        expect(panel()?.style.top).toBe('76px');
        expect(panel()?.style.visibility).toBe('visible');

        wrapper.unmount();
    });

    it('opens upward when the trigger is near the bottom of the viewport', async () => {
        mockRects(350, 160, 400);

        const wrapper = mount(Menu, { slots: { default: items }, attachTo: document.body, ...realTeleport });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        expect(panel()?.style.top).toBe('186px');

        wrapper.unmount();
    });

    it('right-aligns the panel to the trigger, and keeps it inside the viewport', async () => {
        mockRects(40, 160, 800);

        const wrapper = mount(Menu, { slots: { default: items }, attachTo: document.body, ...realTeleport });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        expect(panel()?.style.left).toBe('1056px');

        wrapper.unmount();
    });
});

describe('the panel escapes the row it belongs to', () => {
    const columns: Column[] = [
        { key: 'staff', label: 'Staff' },
        { key: 'reason', label: 'Reason' },
    ];

    const rows = [
        { id: 1, staff: 'Marek Kowalski', reason: 'Annual leave' },
        { id: 2, staff: 'Ana Duarte', reason: 'Dentist' },
        { id: 3, staff: 'Ana Duarte', reason: 'Training day' },
    ];

    const listContext = () =>
        mount(Table, {
            props: { columns, rows, label: 'Time off' },
            slots: { actions: () => [h(MenuItem, () => 'Show in the diary'), h(MenuItem, () => 'Remove')] },
            attachTo: document.body,
            ...realTeleport,
        });

    it('renders the panel on the body rather than inside the table', async () => {
        const wrapper = listContext();

        await wrapper.findAll('tbody button[aria-haspopup="menu"]')[0].trigger('click');
        await wrapper.vm.$nextTick();

        expect(panel()).not.toBeNull();
        expect(panel()?.parentElement).toBe(document.body);
        expect(wrapper.find('tbody [role="menu"]').exists()).toBe(false);
        expect(panel()?.closest('.overflow-x-auto')).toBeNull();

        wrapper.unmount();
    });

    it('leaves every row’s own text in the DOM, unchanged, while the menu is open', async () => {
        const wrapper = listContext();

        const before = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()));

        await wrapper.findAll('tbody button[aria-haspopup="menu"]')[0].trigger('click');
        await wrapper.vm.$nextTick();

        const after = wrapper.findAll('tbody tr').map((row) => row.findAll('td').map((cell) => cell.text()));

        expect(after).toEqual(before);
        expect(wrapper.find('tbody').text()).toContain('Marek Kowalski');
        expect(wrapper.find('tbody').text()).toContain('Annual leave');
        expect(wrapper.find('tbody').text()).toContain('Ana Duarte');
        expect(wrapper.find('tbody').text()).toContain('Training day');

        wrapper.unmount();
    });

    it('renders every item, including the last one of a long menu', async () => {
        const wrapper = mount(Table, {
            props: { columns, rows, label: 'Overdue' },
            slots: {
                actions: () => [
                    h(MenuItem, () => 'Call'),
                    h(MenuItem, () => 'Mark contacted'),
                    h(MenuItem, () => 'Snooze two weeks'),
                    h(MenuItem, () => 'Snooze a month'),
                    h(MenuItem, () => 'Stop chasing'),
                ],
            },
            attachTo: document.body,
            ...realTeleport,
        });

        await wrapper.findAll('tbody button[aria-haspopup="menu"]')[0].trigger('click');
        await wrapper.vm.$nextTick();

        expect(panel()?.querySelectorAll('[role="menuitem"]')).toHaveLength(5);
        expect(panel()?.textContent).toContain('Stop chasing');

        wrapper.unmount();
    });
});

describe('closing', () => {
    const openMenu = async (onAction = vi.fn()) => {
        const wrapper = mount(Menu, {
            slots: {
                default: () => [
                    h(MenuItem, { onClick: onAction }, () => 'Call'),
                    h(MenuItem, () => 'Mark contacted'),
                ],
            },
            attachTo: document.body,
            ...realTeleport,
        });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        expect(panel()).not.toBeNull();

        return wrapper;
    };

    it('closes on a click outside, and leaves the trigger reporting closed', async () => {
        const wrapper = await openMenu();

        const outside = document.createElement('div');
        document.body.appendChild(outside);
        outside.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(panel()).toBeNull();
        expect(wrapper.get('button').attributes('aria-expanded')).toBe('false');

        wrapper.unmount();
    });

    it('stays open on a mousedown inside the teleported panel', async () => {
        const wrapper = await openMenu();

        panel()?.dispatchEvent(new MouseEvent('mousedown', { bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(panel()).not.toBeNull();

        wrapper.unmount();
    });

    it('closes on escape from an item inside the panel', async () => {
        const wrapper = await openMenu();

        const item = panel()?.querySelector('[role="menuitem"]') as HTMLElement;
        item.dispatchEvent(new KeyboardEvent('keydown', { key: 'Escape', bubbles: true }));
        await wrapper.vm.$nextTick();

        expect(panel()).toBeNull();

        wrapper.unmount();
    });

    it('closes on escape from the trigger', async () => {
        const wrapper = await openMenu();

        await wrapper.get('div').trigger('keydown', { key: 'Escape' });

        expect(panel()).toBeNull();

        wrapper.unmount();
    });

    it('runs the action and closes when an item is chosen', async () => {
        const onAction = vi.fn();
        const wrapper = await openMenu(onAction);

        const item = panel()?.querySelector('[role="menuitem"]') as HTMLElement;
        item.click();
        await wrapper.vm.$nextTick();

        expect(onAction).toHaveBeenCalledTimes(1);
        expect(panel()).toBeNull();

        wrapper.unmount();
    });

    it('reopens cleanly after being closed', async () => {
        const wrapper = await openMenu();

        await wrapper.get('button').trigger('click');
        expect(panel()).toBeNull();

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();
        expect(panel()).not.toBeNull();
        expect(panel()?.querySelectorAll('[role="menuitem"]')).toHaveLength(2);

        wrapper.unmount();
    });
});

describe('keyboard navigation reaches the teleported items', () => {
    it('moves through the items with the arrow keys', async () => {
        const wrapper = mount(Menu, { slots: { default: items }, attachTo: document.body, ...realTeleport });

        await wrapper.get('button').trigger('click');
        await wrapper.vm.$nextTick();

        const list = Array.from(panel()?.querySelectorAll('[role="menuitem"]') ?? []);
        expect(document.activeElement).toBe(list[0]);

        await wrapper.get('div').trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(list[1]);

        await wrapper.get('div').trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(list[0]);

        wrapper.unmount();
    });
});
