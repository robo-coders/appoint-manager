import HiddenContact from '@/Components/ui/HiddenContact.vue';
import PhoneLink from '@/Components/ui/PhoneLink.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

const NOTICE = 'Contact hidden — ask an owner';

describe('HiddenContact', () => {
    it('shows the masked value rather than an empty cell', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23' } });

        expect(wrapper.text()).toContain('••••••23');
    });

    it('states why the value is withheld, to a mouse and to a screen reader', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23' } });

        expect(wrapper.attributes('title')).toBe(NOTICE);
        expect(wrapper.find('.sr-only').text()).toBe(NOTICE);
    });

    it('spells the reason out in full on a record page', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23', verbose: true } });

        expect(wrapper.text()).toContain(NOTICE);
        expect(wrapper.text()).toContain('••••••23');
        expect(wrapper.find('.sr-only').exists()).toBe(false);
    });

    it('stands a value in with bullets when there is no partial form', () => {
        const wrapper = mount(HiddenContact);

        expect(wrapper.find('.numeral').text()).toBe('••••••');
        expect(wrapper.attributes('title')).toBe(NOTICE);
        expect(wrapper.find('.sr-only').text()).toBe(NOTICE);
    });

    it('does not render a tel: link, because there is nothing dialable', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23' } });

        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('tel:');
    });

    it('does not look like a customer who never gave a number', () => {
        const absent = mount(PhoneLink, { props: { phone: null } });

        expect(absent.text()).toBe('—');

        for (const withheld of [
            mount(HiddenContact, { props: { masked: '••••••23' } }),
            mount(HiddenContact),
        ]) {
            expect(withheld.find('.numeral').text()).not.toBe('—');
            expect(withheld.find('.numeral').text()).toContain('•');
            expect(withheld.attributes('title')).toBe(NOTICE);
        }

        expect(absent.attributes('title')).toBeUndefined();
    });

    it('leaves a readable number as a tel: link, for contrast', () => {
        const wrapper = mount(PhoneLink, { props: { phone: '07700900123' } });

        expect(wrapper.find('a').attributes('href')).toBe('tel:07700900123');
    });
});
