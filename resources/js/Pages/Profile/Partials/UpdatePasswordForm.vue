<script setup lang="ts">
import { ref } from 'vue';
import Button from '@/Components/ui/Button.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const passwordInput = ref<InstanceType<typeof TextInput> | null>(null);
const currentPasswordInput = ref<InstanceType<typeof TextInput> | null>(null);

const form = useForm({ current_password: '', password: '', password_confirmation: '' });

const updatePassword = () => {
    form.put(route('password.update'), {
        preserveScroll: true,
        onSuccess: () => form.reset(),
        onError: () => {
            if (form.errors.password) {
                form.reset('password', 'password_confirmation');
                passwordInput.value?.focus();
            }
            if (form.errors.current_password) {
                form.reset('current_password');
                currentPasswordInput.value?.focus();
            }
        },
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-17">Password</h2>
            <p class="mt-1 text-13 text-ink-2">A long, random password is the one that keeps the diary yours.</p>
        </header>

        <form class="mt-4 space-y-4" @submit.prevent="updatePassword">
            <TextInput
                ref="currentPasswordInput"
                v-model="form.current_password"
                label="Current password"
                type="password"
                autocomplete="current-password"
                :error="form.errors.current_password"
            />
            <TextInput
                ref="passwordInput"
                v-model="form.password"
                label="New password"
                type="password"
                autocomplete="new-password"
                :error="form.errors.password"
            />
            <TextInput
                v-model="form.password_confirmation"
                label="Confirm new password"
                type="password"
                autocomplete="new-password"
                :error="form.errors.password_confirmation"
            />

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
