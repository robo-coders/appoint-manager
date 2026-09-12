<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import RadioGroup from '@/Components/ui/RadioGroup.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { Step } from '@/Components/ui/StepProgress.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, reactive, ref, watch } from 'vue';

const props = defineProps<{
    terms: { lead: string; price: string; tail: string };
    steps: Step[];
    businessTypes: { value: string; label: string; note: string }[];
    currencies: { value: string; label: string; symbol: string }[];
    currencyCountries: Record<string, { value: string; label: string }[]>;
    defaultCurrency: string;
}>();

const form = useForm({
    business_name: '',
    business_type: '',
    currency: props.defaultCurrency,
    country: props.currencyCountries[props.defaultCurrency]?.[0]?.value ?? '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

type Field =
    | 'business_name'
    | 'business_type'
    | 'currency'
    | 'country'
    | 'name'
    | 'email'
    | 'password'
    | 'password_confirmation';

const FIELDS: Field[] = [
    'business_name',
    'business_type',
    'currency',
    'country',
    'name',
    'email',
    'password',
    'password_confirmation',
];

const EMAIL = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;

const PASSWORD_MIN = 8;

const check: Record<Field, () => string> = {
    business_name: () => {
        const value = form.business_name.trim();

        if (!value) return 'Enter the name clients will see.';

        return value.length < 2 ? 'That is too short to be a business name.' : '';
    },
    business_type: () => (form.business_type ? '' : 'Choose the kind of business this is.'),
    currency: () => (form.currency ? '' : 'Choose the currency you charge in.'),
    country: () => (form.country ? '' : 'Choose where the business is based.'),
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
    business_name: false,
    business_type: false,
    currency: false,
    country: false,
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

const businessNameField = ref<InstanceType<typeof TextInput> | null>(null);
const typeField = ref<InstanceType<typeof RadioGroup> | null>(null);
const currencyField = ref<InstanceType<typeof Select> | null>(null);
const countryField = ref<InstanceType<typeof Select> | null>(null);
const nameField = ref<InstanceType<typeof TextInput> | null>(null);
const emailField = ref<InstanceType<typeof TextInput> | null>(null);
const passwordField = ref<InstanceType<typeof TextInput> | null>(null);
const confirmationField = ref<InstanceType<typeof TextInput> | null>(null);

const focusable = (): Record<Field, { focus: () => void } | null> => ({
    business_name: businessNameField.value,
    business_type: typeField.value,
    currency: currencyField.value,
    country: countryField.value,
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

const typeOptions = computed(() =>
    props.businessTypes.map((type) => ({ value: type.value, label: type.label, hint: type.note })),
);

const currencyOptions = computed(() =>
    props.currencies.map((currency) => ({
        value: currency.value,
        label: `${currency.symbol}  ${currency.label} (${currency.value})`,
    })),
);

const countryOptions = computed(() => props.currencyCountries[form.currency] ?? []);

const currencyHint = computed(() => {
    const symbol = props.currencies.find((currency) => currency.value === form.currency)?.symbol;

    return symbol ? `Clients see prices as ${symbol}. Payouts settle in this currency.` : '';
});

watch(
    () => form.currency,
    () => {
        if (!countryOptions.value.some((country) => country.value === form.country)) {
            form.country = countryOptions.value[0]?.value ?? '';
        }
    },
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

            <Select
                ref="currencyField"
                v-model="form.currency"
                label="Currency"
                :hint="currencyHint"
                :options="currencyOptions"
                :error="errorFor('currency')"
                required
                @blur="touched.currency = true"
            />

            <Select
                ref="countryField"
                v-model="form.country"
                label="Where the business is based"
                hint="Sets where deposits are paid out. You cannot change this later."
                :options="countryOptions"
                :error="errorFor('country')"
                required
                @blur="touched.country = true"
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
