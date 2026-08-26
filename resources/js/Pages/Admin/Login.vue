<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import AppLogo from '@/Components/AppLogo.vue';
import { Head, useForm } from '@inertiajs/vue3';

const form = useForm({ email: '', password: '' });

const submit = () => form.post(route('admin.login.store'), { onFinish: () => form.reset('password') });
</script>

<template>
    <Head title="Console" />

    <div class="flex min-h-screen flex-col items-center justify-center bg-paper px-4">
        <div class="w-full max-w-sm">
            <AppLogo :size="20" />
            <h1 class="mt-6 text-20">Console</h1>
            <p class="mt-1 text-13 text-ink-2">Appoint Manager staff only.</p>

            <form class="mt-6 space-y-4" @submit.prevent="submit">
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
        </div>
    </div>
</template>
