<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

/**
 * Choose a new password, from a link.
 *
 * The email is `readonly` rather than editable. It is the address the token was
 * issued for — changing it cannot succeed, so an editable field here is a
 * control whose only outcome is an error. It stays visible, because the person
 * arriving from an email needs to see which account they are about to change,
 * and `autocomplete="username"` keeps password managers able to file the new
 * password against the right entry.
 */
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
    <GuestLayout title="Choose a new password" lede="Then we will sign you straight in." quiet>
        <Head title="Choose a new password" />

        <form class="space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.email"
                label="Email"
                type="email"
                autocomplete="username"
                readonly
                :error="form.errors.email"
            />
            <TextInput
                v-model="form.password"
                label="New password"
                type="password"
                hint="At least eight characters."
                autocomplete="new-password"
                required
                autofocus
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
            <Button type="submit" block :loading="form.processing">Save and sign in</Button>
        </form>
    </GuestLayout>
</template>
