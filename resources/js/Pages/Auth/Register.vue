<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { Step } from '@/Components/ui/StepProgress.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    terms: string;
    steps: Step[];
    businessTypes: { value: string; label: string }[];
}>();

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

            <div>
                <label class="block text-13 text-ink-2 mb-1" for="business_type">What kind of business?</label>
                <select
                    id="business_type"
                    v-model="form.business_type"
                    required
                    class="w-full rounded-[6px] border border-rule bg-paper px-3 py-2 text-14 text-ink"
                >
                    <option value="" disabled>Choose one</option>
                    <option v-for="type in businessTypes" :key="type.value" :value="type.value">
                        {{ type.label }}
                    </option>
                </select>
                <p v-if="form.errors.business_type" class="mt-1 text-12 text-danger">
                    {{ form.errors.business_type }}
                </p>
            </div>

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