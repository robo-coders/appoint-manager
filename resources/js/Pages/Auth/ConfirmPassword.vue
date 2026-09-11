<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ password: '' });

const submit = () => {
    form.post(route('password.confirm'), { onFinish: () => form.reset() });
};
</script>

<template>
    <GuestLayout
        title="Confirm your password"
        lede="You are about to change something we would rather not get wrong. Enter your password to carry on."
        quiet
    >
        <Head title="Confirm your password" />

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
