import Register from '@/Pages/Auth/Register.vue';
import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it } from 'vitest';
import { nextTick } from 'vue';
import { forms, resetForms } from './setup';

/**
 * `/register`, and the two things a signup form is allowed to do to somebody.
 *
 * Not that it validates — the server does that and `RegistrationTest` proves
 * it. What is only true in the browser is *when* a message appears and *what
 * survives* one appearing:
 *
 *   - the confirmation says it does not match before anything is submitted,
 *     because finding that out after a round trip is finding it out twice;
 *   - a rejected form still holds every character that was typed into it,
 *     including both passwords. This page used to empty those two on any
 *     failure, which turned "you mistyped one" into "type both again".
 */

const businessTypes = [
    { value: 'groomer', label: 'Dog grooming', note: 'dogs · per visit' },
    { value: 'therapist', label: 'Therapy', note: 'clients only' },
];

/*
 * `attached` puts the component in the real document, which `focus()` needs:
 * an element outside it can be focused and `document.activeElement` still
 * reads `<body>`. Only the focus test pays for it, because attaching means
 * cleaning up after itself.
 */
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
                // The layout is a shell around a slot; its own rail is tested by
                // the Playwright suite, where it has a width.
                GuestLayout: { template: '<div><slot /><slot name="foot" /></div>' },
                Head: true,
            },
        },
    });

/** Every field, by its visible label, which is how a person addresses it. */
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
        // No request was made to find that out.
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

        // What a 422 arrives as.
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

        // The plain sentence is still what the field points a screen reader at.
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
        // Not under the email, which is not what went wrong.
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

        // And a second submit that gets past the disabled attribute — an Enter
        // keypress in flight — posts nothing.
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
        // Same ink either way: this system's one signal colour means "wrong".
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
        // No "Choose one" option that is not a choice.
        expect(page.text()).not.toContain('Choose one');
    });

    it('takes the caret when it is the thing that was skipped', async () => {
        const page = mountPage(true);

        await fieldOf(page, 'Business name').setValue('Willow Street Grooming');
        await page.find('form').trigger('submit');

        // `ui/RadioGroup` exposes a focus() of its own, so "you have not
        // answered this" lands on the unanswered question.
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
