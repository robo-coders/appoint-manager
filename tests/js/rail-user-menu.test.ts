import RailUserMenu from '@/Components/ui/RailUserMenu.vue';
import { resetThemeForTests } from '@/composables/useTheme';
import { setPageProps } from './setup';
import { mount } from '@vue/test-utils';
import { afterEach, describe, expect, it } from 'vitest';

const menu = () => document.body.querySelector('[role="menu"]');

afterEach(() => {
    resetThemeForTests();
    document.documentElement.removeAttribute('data-theme');
    document.body.innerHTML = '';
});

describe('appearance in the rail user menu', () => {
    it('is absent on the console, where there is no tenant', async () => {
        setPageProps({ tenant: null });
        const wrapper = mount(RailUserMenu, {
            props: { name: 'Rosa Adeyemi', profileHref: '/profile', logoutHref: '/logout' },
            attachTo: document.body,
        });

        await wrapper.get('button[aria-haspopup="menu"]').trigger('click');

        expect(menu()?.textContent).not.toContain('Appearance');
        expect(menu()?.textContent).toContain('Profile');
        expect(menu()?.textContent).toContain('Log out');

        wrapper.unmount();
    });

    it('lists Light, System and Dark, and applying one sets data-theme', async () => {
        setPageProps({ tenant: { id: 1 } });
        const wrapper = mount(RailUserMenu, {
            props: { name: 'Rosa Adeyemi', profileHref: '/profile', logoutHref: '/logout' },
            attachTo: document.body,
        });

        await wrapper.get('button[aria-haspopup="menu"]').trigger('click');

        const radios = wrapper.findAll('[role="menuitemradio"]');
        expect(radios.map((row) => row.text().replace(/\s+/g, ' ').trim())).toEqual([
            'Light',
            'System',
            'Dark',
        ]);

        await radios[2].trigger('click');

        expect(document.documentElement.getAttribute('data-theme')).toBe('dark');
        expect(radios[2].attributes('aria-checked')).toBe('true');

        wrapper.unmount();
    });
});
