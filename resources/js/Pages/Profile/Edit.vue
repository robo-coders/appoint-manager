<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import DeleteUserForm from './Partials/DeleteUserForm.vue';
import UpdatePasswordForm from './Partials/UpdatePasswordForm.vue';
import UpdateProfileInformationForm from './Partials/UpdateProfileInformationForm.vue';
import { Head } from '@inertiajs/vue3';

/**
 * Your own account.
 *
 * The three forms were already on the component library; what this screen still
 * had was Breeze's shape — three white cards floating on the page with no
 * heading above them, so you arrived somewhere with no title and three boxes.
 *
 * Sections separated by hairlines instead. DESIGN.md: containment is a signal
 * and it is spent once, so three cards that contain nothing but a form each are
 * three signals that mean nothing. The page has one heading and three parts.
 *
 * The Breeze *typography* is phase 8's; the structure is this one's.
 */
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
