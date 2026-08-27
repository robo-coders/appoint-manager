<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

/**
 * The console's own door.
 *
 * On `GuestLayout` like every other signed-out screen, and `quiet` — the panel
 * on the operator side explains what the product is to someone who may not
 * know. Two people can reach this page and both of them wrote it.
 *
 * The lede is not decoration either: an IP allowlist and a separate session
 * cookie are why a salon owner who somehow finds this hostname cannot get past
 * it, and saying so is cheaper than answering the question later.
 */
const form = useForm({ email: '', password: '' });

const submit = () => form.post(route('admin.login.store'), { onFinish: () => form.reset('password') });
</script>

<template>
    <GuestLayout title="Console" lede="Staff only. Separate session, separate host, IP allowlist." quiet>
        <Head title="Console" />

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
                label="Password"
                type="password"
                autocomplete="current-password"
                required
                :error="form.errors.password"
            />
            <Button type="submit" block :loading="form.processing">Sign in</Button>
        </form>
    </GuestLayout>
</template>
