<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

/**
 * Ask for a reset link.
 *
 * The sent state replaces the form rather than sitting above it. Leaving the
 * form on screen under "we have sent you a link" invites a second submit, and
 * the second submit is the one that gets throttled — so the person who did
 * exactly what the page suggested is the one told to wait.
 */
defineProps<{ status?: string }>();

const form = useForm({ email: '' });

const submit = () => form.post(route('password.email'));
</script>

<template>
    <GuestLayout
        title="Reset your password"
        lede="Enter the email you sign in with and we will send a link to choose a new one."
    >
        <Head title="Reset your password" />

        <Callout v-if="status" title="Check your email">
            {{ status }}
            <template #action>
                <Link
                    :href="route('login')"
                    class="text-13 text-ink underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                >
                    Back to sign in
                </Link>
            </template>
        </Callout>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.email"
                type="email"
                label="Email"
                :error="form.errors.email"
                autocomplete="username"
                required
                autofocus
            />
            <Button type="submit" block :loading="form.processing">Send the link</Button>
        </form>

        <template #foot>
            <p v-if="!status" class="text-13 text-ink-2">
                Remembered it?
                <Link
                    :href="route('login')"
                    class="text-ink underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                >
                    Back to sign in</Link
                >.
            </p>
        </template>
    </GuestLayout>
</template>
