import CustomerNotesPanel from '@/Components/CustomerNotesPanel.vue';
import { clearToasts, useToasts } from '@/lib/toast';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { forms, resetForms } from '../../../../../tests/js/setup';

const mountPanel = (overrides: Record<string, unknown> = {}) =>
    mount(CustomerNotesPanel, {
        props: {
            customerId: 7,
            text: 'Nervous with the dryer.',
            editorName: 'Erin',
            updatedAt: '22 Aug 2026',
            ...overrides,
        },
    });

const saveButton = (wrapper: ReturnType<typeof mountPanel>) =>
    wrapper.get('[data-testid="customer-notes-save"]');

const type = async (wrapper: ReturnType<typeof mountPanel>, value: string) => {
    await wrapper.get('textarea').setValue(value);
};

beforeEach(() => {
    resetForms();
    clearToasts();
});

describe('the save button', () => {
    it('starts disabled, because nothing has changed', () => {
        expect(saveButton(mountPanel()).attributes('disabled')).toBeDefined();
    });

    it('enables once the note differs and disables again when it is put back', async () => {
        const wrapper = mountPanel();

        await type(wrapper, 'Nervous with the dryer. Radio off.');
        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined();

        await type(wrapper, 'Nervous with the dryer.');
        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();
    });

    it('enables for a first note on a customer who has none', async () => {
        const wrapper = mountPanel({ text: null, editorName: null, updatedAt: null });

        expect(wrapper.text()).toContain('Not edited yet');
        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();

        await type(wrapper, 'First note.');
        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined();
    });
});

describe('saving', () => {
    it('patches the note, and leaves the confirmation to the server flash', async () => {
        const wrapper = mountPanel();

        await type(wrapper, 'Allow ten more minutes.');
        await saveButton(wrapper).trigger('click');

        const form = forms[0];

        expect(form.notes).toBe('Allow ten more minutes.');
        expect(form.patch).toHaveBeenCalledTimes(1);
        expect(String(form.patch.mock.calls[0][0])).toContain('/customers/notes/update/7');

        const options = form.patch.mock.calls[0][1] as { onSuccess: () => void };
        options.onSuccess();
        await wrapper.vm.$nextTick();

        expect(saveButton(wrapper).attributes('disabled')).toBeDefined();
        expect(useToasts().items).toHaveLength(0);
    });

    it('raises an error toast with a retry when the save is refused', async () => {
        const wrapper = mountPanel();

        await type(wrapper, 'A note that will not land.');
        await saveButton(wrapper).trigger('click');

        const form = forms[0];
        const options = form.patch.mock.calls[0][1] as { onError: (errors: Record<string, string>) => void };

        options.onError({ notes: 'That note is too long.' });
        await wrapper.vm.$nextTick();

        const failure = useToasts().items.at(-1);

        expect(failure?.tone).toBe('error');
        expect(failure?.message).toBe('That note is too long.');
        expect(failure?.action?.label).toBe('Retry');

        failure?.action?.run();

        expect(form.patch).toHaveBeenCalledTimes(2);
    });

    it('keeps what was typed and says why when the save is refused', async () => {
        const wrapper = mountPanel();

        await type(wrapper, 'A note that will not land.');
        await saveButton(wrapper).trigger('click');

        const form = forms[0];
        const options = form.patch.mock.calls[0][1] as { onError: (errors: Record<string, string>) => void };

        options.onError({ notes: 'That note is too long.' });
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('That note is too long.');
        expect(wrapper.get('textarea').element.value).toBe('A note that will not land.');
        expect(saveButton(wrapper).attributes('disabled')).toBeUndefined();
    });

    it('falls back to its own message when the failure names no field', async () => {
        const wrapper = mountPanel();

        await type(wrapper, 'Another note.');
        await saveButton(wrapper).trigger('click');

        const options = forms[0].patch.mock.calls[0][1] as { onError: (errors: Record<string, string>) => void };

        options.onError({});
        await wrapper.vm.$nextTick();

        expect(wrapper.text()).toContain('did not save');
        expect(wrapper.get('textarea').element.value).toBe('Another note.');
    });
});

describe('the metadata line', () => {
    it('names who edited it last and when', () => {
        expect(mountPanel().text()).toContain('Last edited by Erin · 22 Aug 2026');
    });
});
