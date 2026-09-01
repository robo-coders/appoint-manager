<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

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
const page = usePage();

const attemptFailed = computed(() => (form.errors.email?.includes('credentials') ? form.errors.email : ''));
const emailError = computed(() => (attemptFailed.value ? '' : form.errors.email));
const expired = computed(() => (page.props.authNotice?.kind === 'expired' ? page.props.authNotice : null));
const incomplete = ref('');

onMounted(() => {
    const stop = router.on('exception', (event) => {
        incomplete.value = 'That sign-in did not complete. Refresh the page and try again.';
        event.preventDefault();
    });

    onUnmounted(stop);
});

const submit = () => {
    incomplete.value = '';
    form.post(route('admin.login.store'), { onFinish: () => form.reset('password') });
};
</script>

<template>
    <GuestLayout title="Console" lede="Staff only. Separate session, separate host, IP allowlist." quiet>
        <Head title="Console" />

        <Callout
            v-if="expired"
            tone="danger"
            :title="expired.title"
            class="mb-6"
            role="alert"
        >
            {{ expired.body }}
        </Callout>
        <Callout
            v-else-if="attemptFailed"
            tone="danger"
            title="That did not sign you in"
            class="mb-6"
            role="alert"
        >
            {{ attemptFailed }}
        </Callout>
        <Callout
            v-else-if="incomplete"
            tone="danger"
            title="That did not sign you in"
            class="mb-6"
            role="alert"
        >
            {{ incomplete }}
        </Callout>

        <form class="space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.email"
                label="Email"
                type="email"
                autocomplete="username"
                required
                autofocus
                :error="emailError"
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
