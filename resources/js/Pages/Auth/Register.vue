<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

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
    <GuestLayout>
        <Head title="Create an account" />

        <form class="space-y-4" @submit.prevent="submit">
            <TextInput v-model="form.business_name" label="Business name" :error="form.errors.business_name" autocomplete="organization" required />
            <TextInput v-model="form.name" label="Your name" :error="form.errors.name" autocomplete="name" required />
            <TextInput v-model="form.email" type="email" label="Email" :error="form.errors.email" autocomplete="username" required />
            <TextInput v-model="form.password" type="password" label="Password" :error="form.errors.password" autocomplete="new-password" required />
            <TextInput
                v-model="form.password_confirmation"
                type="password"
                label="Confirm password"
                :error="form.errors.password_confirmation"
                autocomplete="new-password"
                required
            />
            <div class="flex items-center justify-between gap-3">
                <Link :href="route('login')" class="text-13 text-ink-2 underline decoration-rule underline-offset-4 hover:text-ink">
                    Already have an account
                </Link>
                <Button type="submit" :disabled="form.processing">Create account</Button>
            </div>
        </form>
    </GuestLayout>
</template>
