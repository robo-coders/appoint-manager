<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { Step } from '@/Components/ui/StepProgress.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Set up your business. The shape of this page is `GuestLayout`'s decision.
 *
 * **The business type is `ui/Select`.** It was a raw `<select>` with a
 * hand-written `<label>` above it, its own `rounded-[6px] border-rule bg-paper`
 * class list and its own `<p class="text-12 text-danger">` underneath — the
 * last hand-rolled control on the auth surface, and the reason this file was on
 * the `check:components` list. Three things were wrong with it beyond the
 * duplication: the field sat on `paper` where every other input in the product
 * is `white`, the error was not linked to the control with `aria-describedby`,
 * and `aria-invalid` was never set, so a screen reader was told nothing had
 * gone wrong on the one field a person is most likely to skip.
 *
 * `ui/Select` rather than `ui/Combobox`: there are a handful of trades, not four
 * hundred timezones, and a control that asks you to type before it will show
 * you the list is the wrong one for a list you can read in full.
 */
const props = defineProps<{
    terms: string;
    steps: Step[];
    businessTypes: { value: string; label: string }[];
}>();

/*
 * The empty first option carries the prompt. It is a real option rather than a
 * `placeholder`, because a native select has none — and it keeps the field
 * invalid until a choice is made, which is what makes `required` mean anything
 * here. The server checks the key exists as well.
 */
const businessTypeOptions = computed(() => [
    { value: '', label: 'Choose one' },
    ...props.businessTypes,
]);

const form = useForm({
    business_name: '',
    business_type: '',
    name: '',
    email: '',
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('register'), {
        onFinish: () => {
            form.reset('password', 'password_confirmation');
        },
    });
};
</script>

<template>
    <GuestLayout
        title="Set up your business"
        lede="Four short steps after this one, and then a diary."
        :steps="steps"
        current-step="account"
        :completed-steps="[]"
    >
        <Head title="Set up your business" />

        <form class="space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.business_name"
                label="Business name"
                hint="Clients see this. You can change it later."
                :error="form.errors.business_name"
                autocomplete="organization"
                required
                autofocus
            />

            <Select
                v-model="form.business_type"
                label="What kind of business?"
                :options="businessTypeOptions"
                :error="form.errors.business_type"
                required
            />

            <TextInput
                v-model="form.name"
                label="Your name"
                :error="form.errors.name"
                autocomplete="name"
                required
            />
            <TextInput
                v-model="form.email"
                type="email"
                label="Email"
                :error="form.errors.email"
                autocomplete="username"
                required
            />
            <TextInput
                v-model="form.password"
                type="password"
                label="Password"
                hint="At least eight characters."
                :error="form.errors.password"
                autocomplete="new-password"
                required
            />
            <TextInput
                v-model="form.password_confirmation"
                type="password"
                label="Confirm password"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
                required
            />

            <div class="pt-2">
                <Button type="submit" block :loading="form.processing">Create the account</Button>
                <p class="mt-3 text-12 text-ink-2">{{ terms }}</p>
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