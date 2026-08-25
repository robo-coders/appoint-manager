<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    status?: string;
}>();

const form = useForm({
    email: '',
});

const submit = () => {
    form.post(route('password.email'));
};
</script>

<template>
    <GuestLayout>
        <Head title="Reset your password" />

        <p class="mb-4 text-14 text-ink-2">
            Enter your email and we’ll send a link to choose a new password.
        </p>

        <p v-if="status" class="mb-4 text-13 text-ink-2">{{ status }}</p>

        <form class="space-y-4" @submit.prevent="submit">
            <TextInput v-model="form.email" type="email" label="Email" :error="form.errors.email" autocomplete="username" required />
            <Button type="submit" :disabled="form.processing">Send reset link</Button>
        </form>
    </GuestLayout>
</template>
