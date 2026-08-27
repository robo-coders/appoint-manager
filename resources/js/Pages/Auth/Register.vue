<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { Step } from '@/Components/ui/StepProgress.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

/**
 * Step one of five, and it says so.
 *
 * This screen used to present itself as the whole of signing up — five fields
 * and "Create account" — and then dropped the person into a four-step setup
 * they had not agreed to and could not see the end of. The flow was always five
 * screens; only this one pretended otherwise.
 *
 * So the progress rail starts here (`GuestLayout`'s `steps`), which means the
 * page can be honest about the cost before anybody spends it, and the person
 * who continues has already seen what "Services" and "Opening hours" are.
 *
 * `business_name` is first and it is not an afterthought: it is the only field
 * on this form that is about *them* rather than about an account, it becomes
 * the tenant's name and its public booking slug, and asking for it first is
 * what makes this read as setting up a business rather than as making a login.
 */
defineProps<{ terms: string; steps: Step[] }>();

const form = useForm({
    business_name: '',
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
