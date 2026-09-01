<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * Sign in. The shape of this page is `GuestLayout`'s decision, and the reason
 * is written there.
 *
 * What is this screen's own:
 *
 *   - **`ui/Checkbox`, not a raw `<input type="checkbox">`.** That one control
 *     is the entire reason this file was the last screen on the
 *     `check:components` allow-list. It carried `rounded border-rule` by hand,
 *     which is a square with a different radius and a different border from
 *     every other checkbox in the product.
 *
 *   - **The failed-attempt message is a `Callout`, above the fields.** Laravel
 *     reports "these credentials do not match" on the `email` key, so it used to
 *     render as a 12px line under the email field — the same weight as "enter a
 *     valid email address", for a fact about the whole attempt rather than about
 *     that field. It is the most important thing on the screen after a failed
 *     sign-in and it now looks like it.
 *
 *   - **Nothing blocks paste.** WCAG 2.2 AA 3.3.8 (Accessible Authentication):
 *     a password manager must be able to fill and a person must be able to
 *     paste. `autocomplete` is set on both fields and no handler touches paste.
 */
defineProps<{
    canResetPassword?: boolean;
    status?: string;
    /** The trial length as a sentence. Built in PHP from config. */
    trialInvitation: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const page = usePage();

/*
 * "These credentials do not match our records" arrives on `email`, and it is
 * not a fact about the email field. Anything else on that key is.
 */
const attemptFailed = computed(() => (form.errors.email?.includes('credentials') ? form.errors.email : ''));
const emailError = computed(() => (attemptFailed.value ? '' : form.errors.email));
const expired = computed(() => (page.props.authNotice?.kind === 'expired' ? page.props.authNotice : null));
const incomplete = ref('');

onMounted(() => {
    /*
     * Cross-origin / network failure. The host-mismatch bug used to die here:
     * Inertia never got a response, `onFinish` cleared the password, and the
     * form sat still. Name it, so it cannot be silent again.
     */
    const stop = router.on('exception', (event) => {
        incomplete.value = 'That sign-in did not complete. Refresh the page and try again.';
        event.preventDefault();
    });

    onUnmounted(stop);
});

const submit = () => {
    incomplete.value = '';
    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <GuestLayout title="Sign in" lede="Your diary, where you left it.">
        <Head title="Sign in" />

        <!-- Success, not failure: "your password has been reset" lands here. -->
        <Callout v-if="status" class="mb-6">{{ status }}</Callout>

        <!--
            Three distinct failures, never a blank form. A wrong password, a
            stale CSRF token, and a request that never came back used to look
            identical: password cleared, page unmoved, nothing said. A groomer
            reads that as "the app is broken".
        -->
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
                type="email"
                label="Email"
                :error="emailError"
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

            <div class="flex items-center justify-between gap-4 pt-2">
                <Checkbox v-model="form.remember" label="Keep me signed in" />
                <Link
                    v-if="canResetPassword"
                    :href="route('password.request')"
                    class="text-13 text-ink-2 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:text-ink hover:decoration-ink"
                >
                    Forgotten your password
                </Link>
            </div>

            <Button type="submit" block :loading="form.processing">Sign in</Button>
        </form>

        <template #foot>
            <p class="text-13 text-ink-2">
                No account yet?
                <Link
                    :href="route('register')"
                    class="text-ink underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                >
                    Set up your business</Link
                >.
            </p>
            <p class="mt-1 text-13 text-ink-2">{{ trialInvitation }}</p>
        </template>
    </GuestLayout>
</template>
