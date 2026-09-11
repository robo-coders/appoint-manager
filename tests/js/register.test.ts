import Register from '@/Pages/Auth/Register.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { forms, resetForms } from './setup';

const businessTypes = [
    { value: 'groomer', label: 'Dog grooming', note: 'dogs · per visit' },
    { value: 'therapist', label: 'Therapy', note: 'clients only' },
];

const mountPage = (attached = false) =>
    mount(Register, {
        attachTo: attached ? document.body : undefined,
        props: {
            terms: { lead: 'No card. 30 days free, then', price: '£29 a month', tail: ', and you can stop at any point.' },
            steps: [
                { key: 'account', label: 'Your account' },
                { key: 'basics', label: 'Business basics' },
            ],
            businessTypes,
        },
        global: {
            stubs: {
                GuestLayout: { template: '<div><slot /><slot name="foot" /></div>' },
                Head: true,
            },
        },
    });

const fieldOf = (page: ReturnType<typeof mountPage>, label: string) => {
    const wanted = page
        .findAll('label')
        .find((el) => el.text().replace(/\*$/, '').trim() === label);

    expect(wanted, `no field labelled "${label}"`).toBeTruthy();

    return page.find(`#${wanted!.attributes('for')}`);
};

const fillEverything = async (page: ReturnType<typeof mountPage>, confirmation = 'correct-horse-battery') => {
    await fieldOf(page, 'Business name').setValue('Willow Street Grooming');
    await page.find('input[type="radio"][value="groomer"]').setValue(true);
    await fieldOf(page, 'Your name').setValue('Maya Chen');
    await fieldOf(page, 'Email').setValue('maya@willowstreet.example');
    await fieldOf(page, 'Password').setValue('correct-horse-battery');
    await fieldOf(page, 'Confirm password').setValue(confirmation);
};

beforeEach(() => resetForms());

describe('the passwords not matching', () => {
    it('says so before the form is ever submitted', async () => {
        const page = mountPage();

        await fieldOf(page, 'Password').setValue('correct-horse-battery');
        await fieldOf(page, 'Confirm password').setValue('correct-horse-batery');

        expect(page.text()).toContain('Those two passwords do not match.');
        expect(forms[0].post).not.toHaveBeenCalled();
    });

    it('links the message to the field, so it is not only a red border', async () => {
        const page = mountPage();

        await fieldOf(page, 'Password').setValue('correct-horse-battery');
        const confirmation = fieldOf(page, 'Confirm password');
        await confirmation.setValue('nope-nope-nope');

        expect(confirmation.attributes('aria-invalid')).toBe('true');
        const described = confirmation.attributes('aria-describedby');
        expect(described).toBeTruthy();
        expect(page.find(`#${described}`).text()).toBe('Those two passwords do not match.');
    });

    it('goes quiet the keystroke it matches, rather than on the next submit', async () => {
        const page = mountPage();

        await fieldOf(page, 'Password').setValue('correct-horse-battery');
        await fieldOf(page, 'Confirm password').setValue('correct-horse-batery');
        expect(page.text()).toContain('Those two passwords do not match.');

        await fieldOf(page, 'Confirm password').setValue('correct-horse-battery');
        expect(page.text()).not.toContain('Those two passwords do not match.');
    });

    it('holds its tongue while the confirmation is still being typed', async () => {
        const page = mountPage();

        await fieldOf(page, 'Password').setValue('correct-horse-battery');
        await fieldOf(page, 'Confirm password').setValue('corr');

        expect(page.text()).not.toContain('Those two passwords do not match.');
    });

    it('refuses to submit, and keeps every other field', async () => {
        const page = mountPage();

        await fillEverything(page, 'correct-horse-batery');
        await page.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();

        expect((fieldOf(page, 'Business name').element as HTMLInputElement).value).toBe('Willow Street Grooming');
        expect((fieldOf(page, 'Your name').element as HTMLInputElement).value).toBe('Maya Chen');
        expect((fieldOf(page, 'Email').element as HTMLInputElement).value).toBe('maya@willowstreet.example');
        expect((fieldOf(page, 'Password').element as HTMLInputElement).value).toBe('correct-horse-battery');
        expect((fieldOf(page, 'Confirm password').element as HTMLInputElement).value).toBe('correct-horse-batery');
        expect((page.find('input[type="radio"][value="groomer"]').element as HTMLInputElement).checked).toBe(true);
    });
});

describe('a submit the server rejects', () => {
    it('clears nothing — not even the two password fields', async () => {
        const page = mountPage();

        await fillEverything(page);
        await page.find('form').trigger('submit');
        expect(forms[0].post).toHaveBeenCalled();

        forms[0].setError('email', 'An account with this email already exists.');
        await nextTick();

        expect((fieldOf(page, 'Business name').element as HTMLInputElement).value).toBe('Willow Street Grooming');
        expect((fieldOf(page, 'Your name').element as HTMLInputElement).value).toBe('Maya Chen');
        expect((fieldOf(page, 'Password').element as HTMLInputElement).value).toBe('correct-horse-battery');
        expect((fieldOf(page, 'Confirm password').element as HTMLInputElement).value).toBe('correct-horse-battery');
        expect((page.find('input[type="radio"][value="groomer"]').element as HTMLInputElement).checked).toBe(true);
    });

    it('answers an already-registered email with a real link to the door', async () => {
        const page = mountPage();

        await fillEverything(page);
        forms[0].setError('email', 'An account with this email already exists.');
        await nextTick();

        const email = fieldOf(page, 'Email');
        const message = page.find(`#${email.attributes('aria-describedby')}`);

        expect(message.text()).toContain('An account with this email already exists');
        const link = message.find('a');
        expect(link.exists()).toBe(true);
        expect(link.attributes('href')).toContain('/login');
        expect(link.text()).toBe('sign in instead');

        expect(email.attributes('aria-invalid')).toBe('true');
    });

    it('puts a field error under its own field, not in a list at the top', async () => {
        const page = mountPage();

        forms[0].setError('name', 'Enter your name.');
        await nextTick();

        const name = fieldOf(page, 'Your name');
        expect(page.find(`#${name.attributes('aria-describedby')}`).text()).toBe('Enter your name.');
    });

    it('lifts a lockout out of the email field and into a banner', async () => {
        const page = mountPage();

        forms[0].setError('email', 'Too many attempts. Try again in 2 minutes.');
        await nextTick();

        expect(page.text()).toContain('Too many attempts. Try again in 2 minutes.');
        expect(fieldOf(page, 'Email').attributes('aria-invalid')).toBeUndefined();
    });
});

describe('the state of the button', () => {
    it('says what it is doing and cannot be pressed twice', async () => {
        const page = mountPage();

        const button = page.find('button[type="submit"]');
        expect(button.text()).toBe('Create the account');

        forms[0].processing = true;
        await nextTick();

        expect(button.text()).toBe('Creating account…');
        expect(button.attributes('disabled')).toBeDefined();
        expect(button.attributes('aria-busy')).toBe('true');

        await fillEverything(page);
        await page.find('form').trigger('submit');
        expect(forms[0].post).not.toHaveBeenCalled();
    });
});

describe('the password requirement', () => {
    it('is stated in ink and confirmed with a glyph, never in colour', async () => {
        const page = mountPage();

        const hint = () => page.findAll('p').find((el) => el.text().includes('At least eight characters'));

        expect(hint()?.text()).toBe('At least eight characters.');
        expect(hint()?.classes()).toContain('text-ink-2');

        await fieldOf(page, 'Password').setValue('correct-horse-battery');

        expect(hint()?.text()).toBe('✓ At least eight characters.');
        expect(hint()?.classes()).toContain('text-ink-2');
    });
});

describe('the trade', () => {
    it('is the same control the next step confirms it with, notes and all', () => {
        const page = mountPage();

        const radios = page.findAll('input[type="radio"]');
        expect(radios).toHaveLength(businessTypes.length);
        expect(page.text()).toContain('Dog grooming');
        expect(page.text()).toContain('dogs · per visit');
        expect(page.text()).not.toContain('Choose one');
    });

    it('takes the caret when it is the thing that was skipped', async () => {
        const page = mountPage(true);

        await fieldOf(page, 'Business name').setValue('Willow Street Grooming');
        await page.find('form').trigger('submit');

        expect(document.activeElement).toBe(page.find('input[type="radio"]').element);

        page.unmount();
    });

    it('is asked for by name when it is skipped', async () => {
        const page = mountPage();

        await fieldOf(page, 'Business name').setValue('Willow Street Grooming');
        await fieldOf(page, 'Your name').setValue('Maya Chen');
        await fieldOf(page, 'Email').setValue('maya@willowstreet.example');
        await fieldOf(page, 'Password').setValue('correct-horse-battery');
        await fieldOf(page, 'Confirm password').setValue('correct-horse-battery');
        await page.find('form').trigger('submit');

        expect(forms[0].post).not.toHaveBeenCalled();
        expect(page.text()).toContain('Choose the kind of business this is.');
    });
});

describe('the price', () => {
    it('is set in mono, like every other figure in the product', () => {
        const page = mountPage();

        const price = page.findAll('span').find((el) => el.text() === '£29 a month');
        expect(price?.classes()).toContain('font-mono');
    });
});
