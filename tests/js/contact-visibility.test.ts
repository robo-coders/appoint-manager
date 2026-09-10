import HiddenContact from '@/Components/ui/HiddenContact.vue';
import PhoneLink from '@/Components/ui/PhoneLink.vue';
import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';

/**
 * The masked state, when the policy says no.
 *
 * The server has already decided and already stripped the value — see
 * `App\Support\ContactVisibility`. What is left for the browser is a
 * presentation question with one real trap in it: **a withheld number must not
 * look like an absent one.**
 *
 * Those two facts mean opposite things to the person reading the screen. "No
 * number on file" is a record to go and fix; "you may not see this number" is a
 * colleague to go and ask. `ui/PhoneLink` already renders an em dash for the
 * first, so the second cannot also be an em dash and nothing else.
 */

const NOTICE = 'Contact hidden — ask an owner';

describe('HiddenContact', () => {
    it('shows the masked value rather than an empty cell', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23' } });

        expect(wrapper.text()).toContain('••••••23');
    });

    /*
     * The reason has to be reachable without a mouse. It is a `title` for a
     * hover and an `sr-only` span for a screen reader, because the compact form
     * lives in a table cell where a sentence would not fit.
     */
    it('states why the value is withheld, to a mouse and to a screen reader', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23' } });

        expect(wrapper.attributes('title')).toBe(NOTICE);
        expect(wrapper.find('.sr-only').text()).toBe(NOTICE);
    });

    it('spells the reason out in full on a record page', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23', verbose: true } });

        expect(wrapper.text()).toContain(NOTICE);
        expect(wrapper.text()).toContain('••••••23');
        // The long form is the sentence itself, not a tooltip on a dash.
        expect(wrapper.find('.sr-only').exists()).toBe(false);
    });

    /*
     * An email, which has no partial form worth showing. It still has to look
     * like a withheld value rather than an empty cell, so a fixed run of
     * bullets stands in — never the em dash that means "none on file".
     */
    it('stands a value in with bullets when there is no partial form', () => {
        const wrapper = mount(HiddenContact);

        // The *visible value*, not the whole node — the notice itself contains
        // an em dash, so asserting over `text()` would test nothing.
        expect(wrapper.find('.numeral').text()).toBe('••••••');
        expect(wrapper.attributes('title')).toBe(NOTICE);
        expect(wrapper.find('.sr-only').text()).toBe(NOTICE);
    });

    it('does not render a tel: link, because there is nothing dialable', () => {
        const wrapper = mount(HiddenContact, { props: { masked: '••••••23' } });

        expect(wrapper.find('a').exists()).toBe(false);
        expect(wrapper.html()).not.toContain('tel:');
    });

    /*
     * The distinction the whole component exists for. A masked number and a
     * missing number must not render the same, or the mask is invisible.
     */
    it('does not look like a customer who never gave a number', () => {
        const absent = mount(PhoneLink, { props: { phone: null } });

        expect(absent.text()).toBe('—');

        // Both withheld shapes — a masked number, and a detail with no partial
        // form at all — must read differently from "none on file".
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
