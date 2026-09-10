<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import RadioGroup from '@/Components/ui/RadioGroup.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { Step } from '@/Components/ui/StepProgress.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

/**
 * Set up your business. Step one of six, and the only one of them signed out.
 *
 * The shape of the page is `GuestLayout`'s decision: a working column and a
 * quiet one, with the setup progress in the quiet one. What this file owns is
 * the form, and specifically the four ways it can fail.
 *
 * ── The trade is `ui/RadioGroup`, not `ui/Select` ─────────────────────────
 *
 * It was a `ui/Select` whose first option was an empty one reading "Choose
 * one", which is a placeholder wearing an option's clothes: the control's
 * resting state was a value that is not allowed, and `required` on a native
 * select is the browser's grey tooltip rather than this product's message.
 *
 * More to the point, `Onboarding/Index.vue` asks the same question one screen
 * later — "What kind of appointments do you take?" — and answers it with
 * `ui/RadioGroup`, options visible, the vertical's own note under each. Two
 * consecutive screens asking one question with two different controls is not a
 * detail somebody notices and forgives; it is the moment the flow stops
 * looking like one flow. Same control, same list, same notes, from
 * `Vertical::note()`.
 *
 * ── Every failure has a place to appear ───────────────────────────────────
 *
 * Four kinds, and they are not the same event, so they do not share a slot:
 *
 *   a field is wrong      — under that field, on blur, before any request
 *   the email is taken    — under the email, with the door in it
 *   the form is locked    — a banner: it is about the form, not a field
 *   the request never     — a banner, neutral in tone, dismissible, and
 *   completed               nothing typed is touched
 *
 * ── Nothing is ever cleared ───────────────────────────────────────────────
 *
 * This form used to `form.reset('password', 'password_confirmation')` in
 * `onFinish`, which runs on failure as well as success. So the one failure a
 * person is most likely to hit — a mistyped confirmation — was answered by
 * emptying both password fields, and the correction was "type both of them
 * again", six fields into a form. `onFinish` is gone. On success this page is
 * replaced by a redirect, so there is nothing to clean up.
 */
const props = defineProps<{
    /**
     * The small print, in three pieces: the price arrives on its own so it can
     * be set in mono like every other figure in the product. See
     * `RegisteredUserController::create()`.
     */
    terms: { lead: string; price: string; tail: string };
    steps: Step[];
    businessTypes: { value: string; label: string; note: string }[];
}>();

const form = useForm({
    business_name: '',
    business_type: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

type Field =
    | 'business_name'
    | 'business_type'
    | 'name'
    | 'email'
    | 'password'
    | 'password_confirmation';

/** In the order they are read, which is the order they are focused in. */
const FIELDS: Field[] = ['business_name', 'business_type', 'name', 'email', 'password', 'password_confirmation'];

/*
 * Deliberately not an email-shaped regex from a library. Everything past "one
 * @ and a dot after it" is a guess about somebody else's address, and the only
 * check that means anything is the one the server does against the unique
 * index. This exists to catch `maya@gmail` — an address one `.com` short —
 * before it costs a round trip.
 */
const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const PASSWORD_MIN = 8;

/**
 * The client-side half of `RegisterRequest`.
 *
 * The wording is copied from that file's `messages()` on purpose: the same
 * failure has to read the same way whether the browser noticed it or the server
 * did, or the form looks like two different forms disagreeing about what is
 * wrong. Where the two can differ they do — the server owns "already
 * registered" and the password policy, which no browser can know.
 */
const check: Record<Field, () => string> = {
    business_name: () => {
        const value = form.business_name.trim();

        if (!value) return 'Enter the name clients will see.';

        return value.length < 2 ? 'That is too short to be a business name.' : '';
    },
    business_type: () => (form.business_type ? '' : 'Choose the kind of business this is.'),
    name: () => (form.name.trim() ? '' : 'Enter your name.'),
    email: () => {
        const value = form.email.trim();

        if (!value) return 'Enter your email address.';

        return EMAIL.test(value) ? '' : "This doesn't look like a full email address.";
    },
    password: () => {
        if (!form.password) return 'Choose a password.';

        return form.password.length < PASSWORD_MIN ? 'Use at least eight characters.' : '';
    },
    password_confirmation: () => {
        if (!form.password_confirmation) return 'Type the password again.';

        return form.password_confirmation === form.password ? '' : 'Those two passwords do not match.';
    },
};

/*
 * A field says nothing until it has been left, and then says it live.
 *
 * On blur rather than on every keystroke, because "enter your email address" is
 * not news to somebody halfway through typing one. After that the message
 * recomputes as they type, so a correction clears it immediately rather than on
 * the next submit — which is the half of on-blur validation that most forms
 * leave out and the reason people re-read a message they have already fixed.
 */
const touched = reactive<Record<Field, boolean>>({
    business_name: false,
    business_type: false,
    name: false,
    email: false,
    password: false,
    password_confirmation: false,
});

/*
 * The confirmation is the exception: it speaks as it is typed, the moment what
 * has been typed stops being the start of the password.
 *
 * "Those two passwords do not match" while somebody is three characters into
 * retyping it is noise, so a value that is still a prefix says nothing. A value
 * that has diverged is a mismatch already and cannot become anything else by
 * being typed further, which is the keystroke worth saying it on.
 *
 * The rule this replaced was "once it is at least as long as the password", and
 * it was wrong on the commonest typo there is: a dropped character makes the
 * confirmation *shorter*, so `correct-horse-batery` against
 * `correct-horse-battery` stayed silent until the field was left.
 */
const confirmationSpeaks = computed(() => {
    if (touched.password_confirmation) return true;

    const typed = form.password_confirmation;

    return typed.length > 0 && !form.password.startsWith(typed);
});

const speaks = (field: Field) => (field === 'password_confirmation' ? confirmationSpeaks.value : touched[field]);

/**
 * What is shown under a field: what we know, then what the server said.
 *
 * The client's message wins when there is one, because it is about the value
 * currently in the box; a server error is about the value that was posted, and
 * survives until the next post so that a rejected form does not go quiet the
 * moment somebody touches it.
 */
const errorFor = (field: Field) => (speaks(field) ? check[field]() : '') || form.errors[field] || '';

/*
 * "An account with this email already exists." — matched by its words, the way
 * `Auth/Login.vue` matches its own two. The alternative is a second key in the
 * Inertia payload whose only job is to say which of two email errors this is,
 * and the message is already the thing that has to be right.
 */
const emailTaken = computed(() => /already exists/i.test(form.errors.email ?? ''));

/*
 * The lockout, reported on `email` because the form request has to report it
 * somewhere. It is not about the email, so it does not render under it.
 */
const lockedOut = computed(() => (/^too many/i.test(form.errors.email ?? '') ? form.errors.email : ''));

const emailError = computed(() => (lockedOut.value ? '' : errorFor('email')));

/*
 * The password requirement, live, and stated as a fact rather than coloured in.
 *
 * A checkmark when it is met, the same sentence in the same ink when it is not.
 * No green, and no red: this system has one signal colour and it means "this is
 * wrong", which an eight-character rule you have not finished typing is not.
 */
const passwordHint = computed(() =>
    form.password.length >= PASSWORD_MIN ? '✓ At least eight characters.' : 'At least eight characters.',
);

/*
 * The request that never came back: a dropped connection, or a response that
 * was not an Inertia one — a proxy's error page, a 500 rendered as HTML.
 * Untouched, Inertia answers the second of those with a full-screen modal
 * containing somebody else's error page, which is the generic error page this
 * form is not allowed to show. Both are the same fact to the person filling in
 * the form, so both say the same thing, and neither is a validation failure:
 * nothing is cleared and the button comes straight back.
 */
const transportError = ref('');

const NOT_SENT = 'Something went wrong and the account was not created. Everything you typed is still here — try again.';

onMounted(() => {
    const stopException = router.on('exception', (event) => {
        transportError.value = NOT_SENT;
        event.preventDefault();
    });

    const stopInvalid = router.on('invalid', (event) => {
        transportError.value = NOT_SENT;
        event.preventDefault();
    });

    onUnmounted(() => {
        stopException();
        stopInvalid();
    });
});

const businessNameField = ref<InstanceType<typeof TextInput> | null>(null);
const typeField = ref<InstanceType<typeof RadioGroup> | null>(null);
const nameField = ref<InstanceType<typeof TextInput> | null>(null);
const emailField = ref<InstanceType<typeof TextInput> | null>(null);
const passwordField = ref<InstanceType<typeof TextInput> | null>(null);
const confirmationField = ref<InstanceType<typeof TextInput> | null>(null);

/*
 * One lookup rather than six branches, because the only thing the caller wants
 * to know is "which control does this field name". `ui/RadioGroup` exposes a
 * `focus()` of its own — the chosen radio, or the first — so the trade is in
 * here on the same terms as the text fields.
 */
const focusable = (): Record<Field, { focus: () => void } | null> => ({
    business_name: businessNameField.value,
    business_type: typeField.value,
    name: nameField.value,
    email: emailField.value,
    password: passwordField.value,
    password_confirmation: confirmationField.value,
});

const submit = () => {
    /*
     * The double-submit guard, and it is not the disabled attribute's job
     * alone: a second Enter keypress lands before Vue has patched the button,
     * and two of these requests are two businesses.
     */
    if (form.processing) return;

    transportError.value = '';

    // Everything speaks from here on, so a submitted form is never a form with
    // a silent failure in it.
    FIELDS.forEach((field) => (touched[field] = true));

    const firstBad = FIELDS.find((field) => check[field]());

    if (firstBad) {
        // Focus follows the message, so the page has never said something the
        // person is scrolled past.
        focusable()[firstBad]?.focus();

        return;
    }

    form.post(route('register'));
};

const typeOptions = computed(() =>
    props.businessTypes.map((type) => ({ value: type.value, label: type.label, hint: type.note })),
);
</script>

<template>
    <GuestLayout
        title="Set up your business"
        lede="Five short steps after this one, and then a diary."
        display-title
        :steps="steps"
        current-step="account"
        :completed-steps="[]"
    >
        <Head title="Set up your business" />

        <!--
            Above the form, because neither of these is about a field. Ordered by
            how final they are: a locked form cannot be submitted at all, so it
            outranks a request that can simply be tried again.

            The lockout is one sentence with no title. `tone="accent"` is the
            ink treatment — `border-ink bg-ink-tint` — which is the same choice
            `Auth/Login.vue` makes for its own lockout: a hard stop painted the
            colour of a typo invites another attempt. A title would be the words
            "Too many attempts" twice, because that is how the sentence starts.
        -->
        <Callout v-if="lockedOut" tone="accent" class="mb-6">
            {{ lockedOut }}
        </Callout>
        <Callout v-else-if="transportError" tone="neutral" title="Not sent" class="mb-6">
            {{ transportError }}
            <template #action>
                <QuietAction @click="transportError = ''">Dismiss</QuietAction>
            </template>
        </Callout>

        <!--
            `novalidate` for the reason `Auth/Login.vue` gives at length: the
            fields keep `required` and `type="email"`, because that is what a
            screen reader and a password manager read, and the browser's grey
            tooltip in the OS font is not this product's error message. Turning
            it off takes on the obligation of catching everything it caught,
            which is what `check` above is.
        -->
        <form class="space-y-4" novalidate @submit.prevent="submit">
            <TextInput
                ref="businessNameField"
                v-model="form.business_name"
                label="Business name"
                hint="Clients see this. You can change it later."
                :error="errorFor('business_name')"
                autocomplete="organization"
                required
                autofocus
                @blur="touched.business_name = true"
            />

            <RadioGroup
                ref="typeField"
                v-model="form.business_type"
                legend="What kind of business?"
                :options="typeOptions"
                :error="errorFor('business_type')"
                required
            />

            <TextInput
                ref="nameField"
                v-model="form.name"
                label="Your name"
                :error="errorFor('name')"
                autocomplete="name"
                required
                @blur="touched.name = true"
            />

            <TextInput
                ref="emailField"
                v-model="form.email"
                type="email"
                label="Email"
                :error="emailError"
                autocomplete="username"
                required
                @blur="touched.email = true"
            >
                <!--
                    The one error in the product that is not only words. Telling
                    somebody they already have an account and then leaving them
                    to find the way in is the actual failure; `emailError` is
                    still the plain sentence behind `aria-describedby`.
                -->
                <template v-if="emailTaken" #error>
                    An account with this email already exists —
                    <Link
                        :href="route('login')"
                        class="text-danger underline decoration-danger underline-offset-4"
                    >
                        sign in instead</Link
                    >.
                </template>
            </TextInput>

            <TextInput
                ref="passwordField"
                v-model="form.password"
                type="password"
                label="Password"
                :hint="passwordHint"
                :error="errorFor('password')"
                autocomplete="new-password"
                required
                @blur="touched.password = true"
            />
            <TextInput
                ref="confirmationField"
                v-model="form.password_confirmation"
                type="password"
                label="Confirm password"
                :error="errorFor('password_confirmation')"
                autocomplete="new-password"
                required
                @blur="touched.password_confirmation = true"
            />

            <div class="pt-2">
                <!--
                    `accent-solid`: the same clay button as `Continue` on the
                    five onboarding steps after this one, because it is the same
                    action — it moves the flow on. The label changes rather than
                    only spinning, so a screen reader hears what is happening
                    and not just `aria-busy`.
                -->
                <Button type="submit" variant="accent-solid" block :loading="form.processing">
                    {{ form.processing ? 'Creating account…' : 'Create the account' }}
                </Button>
                <p class="mt-3 text-12 text-ink-2">
                    {{ terms.lead }}
                    <span class="font-mono tabular-nums">{{ terms.price }}</span>{{ terms.tail }}
                </p>
            </div>
        </form>

        <template #foot>
            <p class="text-13 text-ink-2">
                Already set up?
                <Link
                    :href="route('login')"
                    class="text-ink underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                >
                    Sign in</Link
                >.
            </p>
        </template>
    </GuestLayout>
</template>
