<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * Verify the email address.
 *
 * The address is on screen. The previous version said "the link we just emailed
 * you" without saying where — which is the exact question a person has when the
 * email has not arrived, and the exact typo they need to see to answer it.
 *
 * `quiet` for the same reason as Confirm password: this person has already
 * signed up. They do not need to be told what the product is.
 */
const props = defineProps<{ status?: string; email: string }>();

const form = useForm({});
const submit = () => form.post(route('verification.send'));

const linkSent = computed(() => props.status === 'verification-link-sent');
</script>

<template>
    <GuestLayout title="Confirm your email" lede="One link, so clients can reach you and we can reach you." quiet>
        <Head title="Confirm your email" />

        <p class="text-14 leading-body text-ink-2">
            We sent a link to
            <span class="font-mono text-13 tabular-nums text-ink">{{ email }}</span
            >. Open it and you are done. If it is not there, check the spam folder before asking for another.
        </p>

        <Callout v-if="linkSent" title="Sent again" class="mt-6">
            A new link is on its way to the same address. The last one has stopped working.
        </Callout>

        <form class="mt-8" @submit.prevent="submit">
            <Button type="submit" block :loading="form.processing">Send the link again</Button>
        </form>

        <template #foot>
            <p class="text-13 text-ink-2">
                Wrong address?
                <Link
                    :href="route('logout')"
                    method="post"
                    as="button"
                    class="text-ink underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                >
                    Sign out</Link
                >
                and start again.
            </p>
        </template>
    </GuestLayout>
</template>
