<script setup lang="ts">
import { nextTick, ref } from 'vue';
import Button from '@/Components/ui/Button.vue';
import Modal from '@/Components/ui/Modal.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { useForm } from '@inertiajs/vue3';

const confirming = ref(false);
const passwordInput = ref<InstanceType<typeof TextInput> | null>(null);

const form = useForm({ password: '' });

const confirm = async () => {
    confirming.value = true;
    await nextTick();
    passwordInput.value?.focus();
};

const close = () => {
    confirming.value = false;
    form.clearErrors();
    form.reset();
};

const deleteUser = () => {
    form.delete(route('profile.destroy'), {
        preserveScroll: true,
        onSuccess: close,
        onError: () => passwordInput.value?.focus(),
    });
};
</script>

<template>
    <section>
        <header>
            <h2 class="text-17">Close your account</h2>
            <p class="mt-1 max-w-measure text-13 text-ink-2">
                Your login is removed and your name is erased. Past bookings keep their staff member so the diary's
                history stays readable.
            </p>
        </header>

        <div class="mt-4">
            <Button variant="danger" @click="confirm">Close account</Button>
        </div>

        <Modal
            :show="confirming"
            title="Close your account?"
            description="This cannot be undone. Enter your password to confirm."
            @close="close"
        >
            <TextInput
                ref="passwordInput"
                v-model="form.password"
                label="Password"
                type="password"
                autocomplete="current-password"
                :error="form.errors.password"
                @keyup.enter="deleteUser"
            />

            <template #footer>
                <Button variant="ghost" @click="close">Keep my account</Button>
                <Button variant="danger" :loading="form.processing" @click="deleteUser">Close account</Button>
            </template>
        </Modal>
    </section>
</template>
