<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const submit = () => {
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout>
        <Head title="Log in" />

        <p v-if="status" class="mb-4 text-13 text-ink-2">{{ status }}</p>

        <form class="space-y-4" @submit.prevent="submit">
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
                :error="form.errors.password"
                autocomplete="current-password"
                required
            />
            <label class="flex min-h-tap items-center gap-2 text-14">
                <input v-model="form.remember" type="checkbox" class="rounded border-rule" />
                Keep me signed in
            </label>
            <div class="flex items-center justify-between gap-3">
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-13 text-ink-2 underline decoration-rule underline-offset-4 hover:text-ink"
                >
                    Forgot your password
                </Link>
                <Button type="submit" :disabled="form.processing">Log in</Button>
            </div>
        </form>
    </GuestLayout>
</template>
