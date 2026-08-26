import Checkbox from '@/Components/ui/Checkbox.vue';
import Combobox from '@/Components/ui/Combobox.vue';
import Field from '@/Components/ui/Field.vue';
import Select from '@/Components/ui/Select.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

/**
 * Error binding, on every field.
 *
 * This is the defect the phase 7 report describes on Settings: nine hand-rolled
 * inputs with no `form.errors` binding at all, so a rejected value came back
 * with **nothing on screen** to say so and the form silently discarded what had
 * been typed. The fix was to put every field on the library — which is only a
 * fix if the library actually wires the error to the control.
 *
 * "Wires it" means three things, and all three are tested here for each field:
 * the message is rendered, the input points at it with `aria-describedby`, and
 * the input says `aria-invalid`. A red border is none of those.
 */

/** Every field in the library that takes an `error`, and how to reach its control. */
const fields = [
    { name: 'TextInput', component: TextInput, control: 'input', props: { label: 'Business name' } },
    { name: 'Textarea', component: Textarea, control: 'textarea', props: { label: 'Notes' } },
    {
        name: 'Select',
        component: Select,
        control: 'select',
        props: { label: 'Status', options: [{ value: 'a', label: 'A' }] },
    },
    {
        name: 'Combobox',
        component: Combobox,
        control: 'button',
        props: { label: 'Timezone', options: [{ value: 'Europe/London', label: 'Europe · London' }] },
    },
] as const;

describe.each(fields)('$name error binding', ({ component, control, props }) => {
    it('renders the message', () => {
        const wrapper = mount(component, { props: { ...props, error: 'That is already taken.', modelValue: '' } });

        expect(wrapper.text()).toContain('That is already taken.');
    });

    it('points the control at the message with aria-describedby', () => {
        const wrapper = mount(component, { props: { ...props, error: 'That is already taken.', modelValue: '' } });

        const described = wrapper.find(control).attributes('aria-describedby');
        expect(described).toBeTruthy();

        const message = wrapper.find(`#${described}`);
        expect(message.exists()).toBe(true);
        expect(message.text()).toBe('That is already taken.');
    });

    it('marks the control invalid', () => {
        const wrapper = mount(component, { props: { ...props, error: 'Nope.', modelValue: '' } });

        expect(wrapper.find(control).attributes('aria-invalid')).toBe('true');
    });

    it('says none of that when there is no error', () => {
        const wrapper = mount(component, { props: { ...props, modelValue: '' } });
        const el = wrapper.find(control);

        expect(el.attributes('aria-invalid')).toBeUndefined();
        expect(el.attributes('aria-describedby')).toBeUndefined();
    });

    it('gives the control a real label, joined by id', () => {
        const wrapper = mount(component, { props: { ...props, modelValue: '' } });

        const id = wrapper.find(control).attributes('id');
        expect(id).toBeTruthy();

        const label = wrapper.find(`label[for="${id}"]`);
        expect(label.exists()).toBe(true);
        expect(label.text()).toContain(props.label);
    });

    it('shows the hint until there is an error to show instead', () => {
        const withHint = mount(component, { props: { ...props, hint: 'Customers see this.', modelValue: '' } });
        expect(withHint.text()).toContain('Customers see this.');

        const withBoth = mount(component, {
            props: { ...props, hint: 'Customers see this.', error: 'Too long.', modelValue: '' },
        });
        // Two lines of small grey text under one field is one line too many;
        // the error is the one that has to be read.
        expect(withBoth.text()).toContain('Too long.');
        expect(withBoth.text()).not.toContain('Customers see this.');
    });
});

describe('the error message itself', () => {
    it('is announced, and in danger', () => {
        const wrapper = mount(TextInput, { props: { label: 'Email', error: 'Enter an email address.', modelValue: '' } });
        const message = wrapper.find('[role="alert"]');

        expect(message.exists()).toBe(true);
        expect(message.classes()).toContain('text-danger');
    });
});

describe('Combobox', () => {
    const options = [
        { value: 'Europe/London', label: 'Europe · London' },
        { value: 'Europe/Lisbon', label: 'Europe · Lisbon' },
        { value: 'America/New_York', label: 'America · New York' },
    ];

    it('shows the chosen option rather than its stored value', () => {
        const wrapper = mount(Combobox, { props: { label: 'Timezone', options, modelValue: 'Europe/London' } });

        expect(wrapper.find('button').text()).toContain('Europe · London');
    });

    /*
     * The reason this replaced a native select: four hundred options cannot be
     * searched, only scrolled. "lon" has to reach London.
     */
    it('filters as you type', async () => {
        const wrapper = mount(Combobox, { props: { label: 'Timezone', options, modelValue: '' } });

        await wrapper.find('button').trigger('click');
        await wrapper.find('input').setValue('lon');

        const shown = wrapper.findAll('[role="option"]').map((o) => o.text());
        expect(shown).toEqual(['Europe · London']);
    });

    it('is a real combobox to assistive tech', async () => {
        const wrapper = mount(Combobox, { props: { label: 'Timezone', options, modelValue: '' } });
        const trigger = wrapper.find('button');

        expect(trigger.attributes('aria-expanded')).toBe('false');

        await trigger.trigger('click');
        expect(wrapper.find('[role="listbox"]').exists()).toBe(true);
        expect(wrapper.findAll('[role="option"]').length).toBe(3);
    });

    /*
     * `mousedown`, not `click`. The option commits on mousedown *by design*:
     * the filter input closes the list on blur, and a blur fires before a click
     * completes — so a click handler would find the list already gone. Driving
     * it the way a mouse does is the point.
     */
    it('emits the value, not the label', async () => {
        const wrapper = mount(Combobox, { props: { label: 'Timezone', options, modelValue: '' } });

        await wrapper.find('button').trigger('click');
        await wrapper.findAll('[role="option"]')[2].trigger('mousedown');

        expect(wrapper.emitted('update:modelValue')?.at(-1)).toEqual(['America/New_York']);
    });

    it('points aria-activedescendant at the highlighted option', async () => {
        const wrapper = mount(Combobox, { props: { label: 'Timezone', options, modelValue: '' } });

        await wrapper.find('button').trigger('click');
        const input = wrapper.find('input');

        const first = input.attributes('aria-activedescendant');
        expect(first).toBeTruthy();
        expect(wrapper.find(`#${first}`).text()).toBe('Europe · London');

        await input.trigger('keydown', { key: 'ArrowDown' });
        expect(wrapper.find(`#${input.attributes('aria-activedescendant')}`).text()).toBe('Europe · Lisbon');
    });
});

describe('Checkbox', () => {
    it('joins its label and hint to the input', () => {
        const wrapper = mount(Checkbox, {
            props: { label: 'Takes bookings', hint: 'Appears as a column in the diary.', modelValue: true },
        });

        const id = wrapper.find('input').attributes('id');
        expect(wrapper.find(`label[for="${id}"]`).text()).toContain('Takes bookings');
        expect(wrapper.text()).toContain('Appears as a column in the diary.');
    });
});

describe('Field', () => {
    it('wraps something the library does not own and still binds its error', () => {
        const wrapper = mount(Field, {
            props: { inputId: 'hours', label: 'Opening hours', error: 'Closes before it opens.' },
            slots: { default: '<div id="hours">a custom control</div>' },
        });

        expect(wrapper.find('label[for="hours"]').text()).toContain('Opening hours');
        expect(wrapper.find('#hours-error').text()).toBe('Closes before it opens.');
    });
});
