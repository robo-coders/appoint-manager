<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    mustVerifyEmail?: boolean;
    status?: string;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Profile" />

        <PageHeader title="Your profile" description="Your details, your password, and the way out." />

        <div class="max-w-measure divide-y divide-rule">
            <div class="pb-8">
                <UpdateProfileInformationForm :must-verify-email="mustVerifyEmail" :status="status" />
            </div>

            <div class="py-8">
                <UpdatePasswordForm />
            </div>

            <div class="pt-8">
                <DeleteUserForm />
            </div>
        </div>
    </AppLayout>
</template>
