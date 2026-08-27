<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

/**
 * Confirm the password before something consequential.
 *
 * `quiet`: the panel on the other auth screens is orientation for someone who
 * may not know what this product is. Anyone reaching this page is signed in and
 * halfway through a task, and a paragraph selling them the product while they
 * are being interrupted reads as an advert placed in their way.
 */
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
