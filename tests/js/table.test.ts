import Table, { type Column } from '@/Components/ui/Table.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import { h } from 'vue';

const columns: Column[] = [
    { key: 'when', label: 'When', width: 'when', sortable: true },
    { key: 'customer', label: 'Customer', sortable: true },
    { key: 'staff', label: 'Staff', width: 'staff', secondary: true },
    { key: 'amount', label: 'Amount', width: 'amount', align: 'right', numeric: true, sortable: true },
];

const rows = [
    { id: 1, when: '2026-03-11 09:00', customer: 'Ruth Kowalczyk', staff: 'Ana', amount: 5200 },
    { id: 2, when: '2026-03-10 09:00', customer: 'Naomi Ellery', staff: 'Ana', amount: 4500 },
    { id: 3, when: '2026-03-10 10:30', customer: 'Dele Okonjo', staff: 'Marek', amount: 1800 },
];

const bodyText = (wrapper: ReturnType<typeof mount>, column: number) =>
    wrapper.findAll('tbody tr').map((row) => row.findAll('td')[column].text());

describe('sorting', () => {
    it('sorts on the value, not on what the cell prints', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });

        wrapper.findAll('thead button')[0].trigger('click');

        return wrapper.vm.$nextTick().then(() => {
            expect(bodyText(wrapper, 0)).toEqual([
                '2026-03-10 09:00',
                '2026-03-10 10:30',
                '2026-03-11 09:00',
            ]);
        });
    });

    it('reverses on a second press and says so in aria-sort', async () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });
        const header = () => wrapper.findAll('thead th')[3];

        expect(header().attributes('aria-sort')).toBe('none');

        await wrapper.findAll('thead button')[2].trigger('click');
        expect(header().attributes('aria-sort')).toBe('ascending');
        expect(bodyText(wrapper, 3)).toEqual(['1800', '4500', '5200']);

        await wrapper.findAll('thead button')[2].trigger('click');
        expect(header().attributes('aria-sort')).toBe('descending');
        expect(bodyText(wrapper, 3)).toEqual(['5200', '4500', '1800']);
    });

    it('leaves the rows alone when the server owns the order', async () => {
        const wrapper = mount(Table, {
            props: { columns, rows, label: 'Bookings', sort: { key: 'when', direction: 'asc' } },
        });

        await wrapper.findAll('thead button')[1].trigger('click');

        expect(wrapper.emitted('sort')).toBeTruthy();
        expect(bodyText(wrapper, 1)).toEqual(['Ruth Kowalczyk', 'Naomi Ellery', 'Dele Okonjo']);
    });

    it('does not offer a sort control on a column that is not sortable', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });

        expect(wrapper.findAll('thead button')).toHaveLength(3);
        expect(wrapper.findAll('thead th')[2].attributes('aria-sort')).toBeUndefined();
    });
});

describe('structure the mockup asks for', () => {
    it('sticks the header and gives it the sunk fill', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });
        const head = wrapper.find('thead');

        expect(head.classes()).toContain('sticky');
        expect(wrapper.find('thead tr').classes()).toContain('bg-paper-sunk');
    });

    it('can be told not to stick, for a table inside a short card', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings', sticky: false } });

        expect(wrapper.find('thead').classes()).not.toContain('sticky');
    });

    it('hairlines the rows and never stripes them', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });

        for (const row of wrapper.findAll('tbody tr')) {
            expect(row.classes()).toContain('border-b');
            expect(row.classes().join(' ')).not.toMatch(/odd:|even:/);
        }
    });

    it('puts numbers right and in mono', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });
        const cell = wrapper.findAll('tbody tr')[0].findAll('td')[3];

        expect(cell.classes()).toContain('text-right');
        expect(cell.classes()).toContain('numeral');
    });

    it('carries an accessible name', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings, earliest first' } });

        expect(wrapper.find('table').attributes('aria-label')).toBe('Bookings, earliest first');
    });
});

describe('the row actions menu', () => {
    it('names each menu after its own row', () => {
        const wrapper = mount(Table, {
            props: {
                columns,
                rows,
                label: 'Bookings',
                rowLabel: (row: Record<string, unknown>) => `Actions for ${row.customer}, ${row.when}`,
            },
            slots: { actions: () => h('span', 'x') },
        });

        const labels = wrapper.findAll('tbody [aria-haspopup="menu"]').map((b) => b.attributes('aria-label'));

        expect(labels).toEqual([
            'Actions for Ruth Kowalczyk, 2026-03-11 09:00',
            'Actions for Naomi Ellery, 2026-03-10 09:00',
            'Actions for Dele Okonjo, 2026-03-10 10:30',
        ]);
    });

    it('adds no actions column when a screen has no actions', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });

        expect(wrapper.findAll('tbody tr')[0].findAll('td')).toHaveLength(4);
    });
});

describe('empty', () => {
    it('shows one sentence and one action, spanning the whole table', () => {
        const wrapper = mount(Table, {
            props: {
                columns,
                rows: [],
                label: 'Bookings',
                emptyTitle: 'No bookings match “otto”',
                emptyDescription: 'Search covers names, not booking notes.',
            },
            slots: { 'empty-action': () => h('button', 'Search notes as well') },
        });

        expect(wrapper.text()).toContain('No bookings match “otto”');
        expect(wrapper.text()).toContain('Search notes as well');
        expect(wrapper.find('tbody td').attributes('colspan')).toBe('4');
    });

    it('counts the actions column in the colspan when there is one', () => {
        const wrapper = mount(Table, {
            props: { columns, rows: [], label: 'Bookings' },
            slots: { actions: () => h('span', 'x') },
        });

        expect(wrapper.find('tbody td').attributes('colspan')).toBe('5');
    });
});

describe('loading', () => {
    it('draws one bar per column, in that column', () => {
        const wrapper = mount(Table, { props: { columns, rows: [], label: 'Bookings', loading: true, loadingRows: 4 } });

        const bodyRows = wrapper.findAll('tbody tr');
        expect(bodyRows).toHaveLength(4);

        for (const row of bodyRows) {
            expect(row.findAll('td')).toHaveLength(columns.length);
        }
    });

    it('adds a bar for the actions column when the table has one', () => {
        const wrapper = mount(Table, {
            props: { columns, rows: [], label: 'Bookings', loading: true, loadingRows: 2 },
            slots: { actions: () => h('span', 'x') },
        });

        expect(wrapper.findAll('tbody tr')[0].findAll('td')).toHaveLength(columns.length + 1);
    });

    it('shows the skeleton instead of the empty state, never both', () => {
        const wrapper = mount(Table, {
            props: { columns, rows: [], label: 'Bookings', loading: true, emptyTitle: 'Nothing here yet' },
        });

        expect(wrapper.text()).not.toContain('Nothing here yet');
    });

    it('hides the footer count while loading, because there is nothing to count', () => {
        const wrapper = mount(Table, {
            props: { columns, rows: [], label: 'Bookings', loading: true },
            slots: { footer: () => 'Showing 1–6 of 12' },
        });

        expect(wrapper.text()).not.toContain('Showing 1–6 of 12');
    });
});

describe('the narrow state', () => {
    const narrow: Column[] = [
        { key: 'when', label: 'When', width: 'when', sortable: true, narrow: 'line' },
        { key: 'customer', label: 'Customer', sortable: true, narrow: 'title' },
        { key: 'staff', label: 'Staff', width: 'staff', secondary: true, narrow: 'line' },
        {
            key: 'amount',
            label: 'Amount',
            width: 'amount',
            align: 'right',
            numeric: true,
            sortable: true,
            narrow: 'meta',
        },
    ];

    it('is not rendered at all unless a column asks for it', () => {
        const wrapper = mount(Table, { props: { columns, rows, label: 'Bookings' } });

        expect(wrapper.find('ul').exists()).toBe(false);
        expect(wrapper.find('table').classes()).not.toContain('hidden');
    });

    it('renders a list beside the table, each hidden at the other one’s width', () => {
        const wrapper = mount(Table, { props: { columns: narrow, rows, label: 'Bookings' } });

        const list = wrapper.find('ul');
        expect(list.exists()).toBe(true);
        expect(list.attributes('aria-label')).toBe('Bookings');

        expect(wrapper.findAll('ul > li')).toHaveLength(3);
        expect(wrapper.findAll('tbody tr')).toHaveLength(3);

        expect(list.element.parentElement?.className).toContain('md:hidden');
        expect(wrapper.find('table').element.parentElement?.className).toContain('hidden');
        expect(wrapper.find('table').element.parentElement?.className).toContain('md:block');
    });

    it('puts the title column first and the meta column with the row menu', () => {
        const wrapper = mount(Table, {
            props: { columns: narrow, rows, label: 'Bookings' },
            slots: { actions: () => h('span', 'act') },
        });

        const first = wrapper.findAll('ul > li')[0];

        expect(first.find('p').text()).toBe('Ruth Kowalczyk');
        expect(first.findAll('p')[1].text()).toContain('2026-03-11 09:00');
        expect(first.findAll('p')[1].text()).toContain('Ana');
        expect(first.text()).toContain('5200');
        expect(first.find('button[aria-haspopup="menu"]').exists()).toBe(true);
    });

    it('shows a secondary column in the list even though the table hides it', () => {
        const wrapper = mount(Table, { props: { columns: narrow, rows, label: 'Bookings' } });

        const staffCell = wrapper.findAll('tbody tr')[0].findAll('td')[2];
        expect(staffCell.classes()).toContain('hidden');

        expect(wrapper.findAll('ul > li')[0].text()).toContain('Ana');
    });

    it('uses the same cell slots as the table, so a screen styles a value once', () => {
        const wrapper = mount(Table, {
            props: { columns: narrow, rows, label: 'Bookings' },
            slots: { 'cell:amount': (p: { value: number }) => h('span', `£${(p.value / 100).toFixed(2)}`) },
        });

        expect(wrapper.findAll('ul > li')[0].text()).toContain('£52.00');
        expect(wrapper.findAll('tbody tr')[0].text()).toContain('£52.00');
    });

    it('carries the empty state and the skeleton into the list as well', () => {
        const empty = mount(Table, {
            props: { columns: narrow, rows: [], label: 'Bookings', emptyTitle: 'No bookings yet' },
        });
        expect(empty.text()).toContain('No bookings yet');
        expect(empty.find('ul > li').exists()).toBe(false);

        const loading = mount(Table, { props: { columns: narrow, rows: [], label: 'Bookings', loading: true } });
        expect(loading.findAll('ul > li').length).toBeGreaterThan(0);
    });
});
