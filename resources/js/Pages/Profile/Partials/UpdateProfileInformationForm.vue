<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Link, useForm, usePage } from '@inertiajs/vue3';

defineProps<{ mustVerifyEmail?: boolean; status?: string }>();

const user = usePage().props.auth.user!;

const form = useForm({ name: user.name, email: user.email });
</script>

<template>
    <section>
        <header>
            <h2 class="text-17">Your details</h2>
            <p class="mt-1 text-13 text-ink-2">Your name and the address we use to reach you.</p>
        </header>

        <form class="mt-4 space-y-4" @submit.prevent="form.patch(route('profile.update'))">
            <TextInput
                v-model="form.name"
                label="Name"
                autocomplete="name"
                required
                :error="form.errors.name"
            />
            <TextInput
                v-model="form.email"
                label="Email"
                type="email"
                autocomplete="username"
                required
                :error="form.errors.email"
            />

            <p v-if="mustVerifyEmail && user.email_verified_at === null" class="text-13 text-ink-2">
                Your email address is unverified.
                <Link
                    :href="route('verification.send')"
                    method="post"
                    as="button"
                    class="text-accent underline decoration-rule underline-offset-4 hover:decoration-ink"
                    >Resend the verification email</Link
                >
                <span v-if="status === 'verification-link-sent'" class="mt-1 block text-ink">
                    A new link is on its way.
                </span>
            </p>

            <div class="flex items-center gap-3">
                <Button type="submit" :loading="form.processing">Save</Button>
                <Transition
                    enter-active-class="transition duration-fast ease-product"
                    enter-from-class="opacity-0"
                    leave-active-class="transition duration-fast ease-product"
                    leave-to-class="opacity-0"
                >
                    <p v-if="form.recentlySuccessful" class="text-13 text-ink-2">Saved.</p>
                </Transition>
            </div>
        </form>
    </section>
</template>
