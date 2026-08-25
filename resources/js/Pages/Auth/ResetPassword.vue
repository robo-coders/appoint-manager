<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/ui/Button.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const props = defineProps<{ email: string; token: string }>();

const form = useForm({
    token: props.token,
    email: props.email,
    password: '',
    password_confirmation: '',
});

const submit = () => {
    form.post(route('password.store'), {
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Choose a new password" />

        <form class="space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.email"
                label="Email"
                type="email"
                autocomplete="username"
                required
                autofocus
                :error="form.errors.email"
            />
            <TextInput
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password"
            />
            <TextInput
                v-model="form.password_confirmation"
                label="Confirm new password"
                type="password"
                autocomplete="new-password"
                required
                :error="form.errors.password_confirmation"
            />
            <Button type="submit" block :loading="form.processing">Reset password</Button>
        </form>
    </GuestLayout>
</template>
