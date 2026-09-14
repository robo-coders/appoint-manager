<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { Step } from '@/Components/ui/StepProgress.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref } from 'vue';

defineProps<{
    terms: { lead: string; price: string; tail: string };
    steps: Step[];
}>();

const form = useForm({
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

type Field = 'name' | 'email' | 'password' | 'password_confirmation';

const FIELDS: Field[] = ['name', 'email', 'password', 'password_confirmation'];

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const PASSWORD_MIN = 8;

const check: Record<Field, () => string> = {
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

const touched = reactive<Record<Field, boolean>>({
    name: false,
    email: false,
    password: false,
    password_confirmation: false,
});

const confirmationSpeaks = computed(() => {
    if (touched.password_confirmation) return true;

    const typed = form.password_confirmation;

    return typed.length > 0 && !form.password.startsWith(typed);
});

const speaks = (field: Field) => (field === 'password_confirmation' ? confirmationSpeaks.value : touched[field]);

const errorFor = (field: Field) => (speaks(field) ? check[field]() : '') || form.errors[field] || '';

const emailTaken = computed(() => /already exists/i.test(form.errors.email ?? ''));

const lockedOut = computed(() => (/^too many/i.test(form.errors.email ?? '') ? form.errors.email : ''));

const emailError = computed(() => (lockedOut.value ? '' : errorFor('email')));

const passwordHint = computed(() =>
    form.password.length >= PASSWORD_MIN ? '✓ At least eight characters.' : 'At least eight characters.',
);

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

const nameField = ref<InstanceType<typeof TextInput> | null>(null);
const emailField = ref<InstanceType<typeof TextInput> | null>(null);
const passwordField = ref<InstanceType<typeof TextInput> | null>(null);
const confirmationField = ref<InstanceType<typeof TextInput> | null>(null);

const focusable = (): Record<Field, { focus: () => void } | null> => ({
    name: nameField.value,
    email: emailField.value,
    password: passwordField.value,
    password_confirmation: confirmationField.value,
});

const submit = () => {
    if (form.processing) return;

    transportError.value = '';

    FIELDS.forEach((field) => (touched[field] = true));

    const firstBad = FIELDS.find((field) => check[field]());

    if (firstBad) {
        focusable()[firstBad]?.focus();

        return;
    }

    form.post(route('register'));
};
</script>

<template>
    <GuestLayout
        title="Set up your business"
        lede="Four short steps after this one, and then a diary."
        display-title
        :steps="steps"
        current-step="account"
        :completed-steps="[]"
    >
        <Head title="Set up your business" />

        <Callout v-if="lockedOut" tone="accent" class="mb-6">
            {{ lockedOut }}
        </Callout>
        <Callout v-else-if="transportError" tone="neutral" title="Not sent" class="mb-6">
            {{ transportError }}
            <template #action>
                <QuietAction @click="transportError = ''">Dismiss</QuietAction>
            </template>
        </Callout>

        <form class="space-y-4" novalidate @submit.prevent="submit">
            <TextInput
                ref="nameField"
                v-model="form.name"
                label="Your name"
                :error="errorFor('name')"
                autocomplete="name"
                required
                autofocus
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
                <Button type="submit" variant="accent-solid" block :loading="form.processing">
                    {{ form.processing ? 'Creating account…' : 'Continue to business basics' }}
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
