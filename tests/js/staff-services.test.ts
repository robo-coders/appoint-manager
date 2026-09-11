import StaffPage from '@/Pages/Staff/Index.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { forms, resetForms, setPageProps } from './setup';

const SERVICES = [
    { id: 1, name: 'Full groom' },
    { id: 2, name: 'Puppy trim' },
];

const person = (overrides: Record<string, unknown> = {}) => ({
    id: 1,
    name: 'Sam Reed',
    email: 'sam@example.com',
    role: 'staff',
    is_bookable: true,
    is_active: true,
    can_see_customer_contacts: true,
    colour: '#71717A',
    initial: 'S',
    role_label: 'Staff',
    hours: 'Mon–Fri · 09:00–17:00',
    weekly_hours: '40 h',
    booked_this_week: 2,
    service_ids: [1],
    ...overrides,
});

const mountPage = (props: Record<string, unknown> = {}) =>
    mount(StaffPage, {
        props: { staff: [person()], services: SERVICES, ...props },
        global: {
            stubs: {
                AppLayout: { template: '<div><slot /></div>' },
                PageHeader: { template: '<div><slot /></div>' },
                Head: true,
                SlideOver: {
                    props: { show: Boolean, title: String },
                    template: '<div v-if="show"><slot /><slot name="footer" /></div>',
                },
            },
        },
    });

const press = (page: ReturnType<typeof mountPage>, label: string) => {
    const button = page.findAll('button').find((node) => node.text() === label);

    if (button === undefined) {
        throw new Error(`No button labelled "${label}"`);
    }

    return button.trigger('click');
};

const checkboxFor = (page: ReturnType<typeof mountPage>, label: string) => {
    const field = page
        .findAll('label')
        .find((node) => node.text() === label)
        ?.element as HTMLLabelElement | undefined;

    if (field === undefined) {
        throw new Error(`No checkbox labelled "${label}"`);
    }

    return page.find<HTMLInputElement>(`#${field.htmlFor}`);
};

beforeEach(() => {
    resetForms();
    setPageProps({ auth: { user: { id: 99 } } });
});

describe('opening the sheet', () => {
    it('ticks the services this person already performs, and only those', async () => {
        const page = mountPage();
        await press(page, 'Edit');

        expect(checkboxFor(page, 'Full groom').element.checked).toBe(true);
        expect(checkboxFor(page, 'Puppy trim').element.checked).toBe(false);
    });

    it('ticks every service for somebody who does not exist yet', async () => {
        const page = mountPage();
        await press(page, 'Add staff');

        expect(checkboxFor(page, 'Full groom').element.checked).toBe(true);
        expect(checkboxFor(page, 'Puppy trim').element.checked).toBe(true);
    });
});

describe('clearing the list', () => {
    it('says nothing while at least one service is ticked', async () => {
        const page = mountPage();
        await press(page, 'Edit');

        expect(page.find('[data-testid="staff-services-warning"]').exists()).toBe(false);
    });

    it('warns as soon as the last tick comes off, without blocking the save', async () => {
        const page = mountPage();
        await press(page, 'Edit');

        await checkboxFor(page, 'Full groom').setValue(false);

        const warning = page.find('[data-testid="staff-services-warning"]');

        expect(warning.exists()).toBe(true);
        expect(warning.text()).toContain("won't be bookable online");
        expect(forms[0].service_ids).toEqual([]);

        expect(page.findAll('button').some((node) => node.text() === 'Save')).toBe(true);
    });

    it('takes the warning away again when a service is ticked back on', async () => {
        const page = mountPage();
        await press(page, 'Edit');

        await checkboxFor(page, 'Full groom').setValue(false);
        expect(page.find('[data-testid="staff-services-warning"]').exists()).toBe(true);

        await checkboxFor(page, 'Puppy trim').setValue(true);

        expect(page.find('[data-testid="staff-services-warning"]').exists()).toBe(false);
        expect(forms[0].service_ids).toEqual([2]);
    });
});

describe('a salon with nothing to assign', () => {
    it('offers a way to add a service rather than a blank list', async () => {
        const page = mountPage({ services: [] });
        await press(page, 'Edit');

        const empty = page.find('[data-testid="staff-services-empty"]');

        expect(empty.exists()).toBe(true);
        expect(empty.text()).toContain('Add a service');
        expect(empty.find('a').attributes('href')).toContain('/services');
    });

    it('does not also warn that nothing is selected', async () => {
        const page = mountPage({ services: [] });
        await press(page, 'Edit');

        expect(page.find('[data-testid="staff-services-warning"]').exists()).toBe(false);
    });
});
