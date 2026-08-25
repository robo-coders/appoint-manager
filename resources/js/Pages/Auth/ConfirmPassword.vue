<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/ui/Button.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });

const submit = () => {
    form.post(route('password.confirm'), { onFinish: () => form.reset() });
};
</script>

<template>
    <GuestLayout>
        <Head title="Confirm your password" />

        <p class="mb-4 text-13 text-ink-2">
            This is a secure area. Please confirm your password before continuing.
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="current-password"
                required
                autofocus
                :error="form.errors.password"
            />
            <Button type="submit" block :loading="form.processing">Confirm</Button>
        </form>
    </GuestLayout>
</template>
