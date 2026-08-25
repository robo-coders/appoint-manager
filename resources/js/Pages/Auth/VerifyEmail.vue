<script setup lang="ts">
import { computed } from 'vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{ status?: string }>();

const form = useForm({});
const submit = () => form.post(route('verification.send'));

const linkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout>
        <Head title="Verify your email" />

        <p class="text-13 text-ink-2">
            Thanks for signing up. Before getting started, could you verify your email address by clicking the link we
            just emailed you? If you didn't receive it, we'll gladly send another.
        </p>

        <Callout v-if="linkSent" class="mt-4" title="Link sent">
            A new verification link has been sent to the email address you registered with.
        </Callout>

        <form class="mt-6 flex items-center justify-between gap-4" @submit.prevent="submit">
            <Button type="submit" :loading="form.processing">Resend verification email</Button>
            <Link
                :href="route('logout')"
                method="post"
                as="button"
                class="text-13 text-ink-2 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:text-ink"
                >Log out</Link
            >
        </form>
    </GuestLayout>
</template>
