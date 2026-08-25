<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

defineProps<{
    status: 'not_started' | 'in_progress' | 'ready';
    currently_due: string[];
    account_id: string | null;
}>();

const form = useForm({});
const connect = () => form.post(route('settings.payments.connect'));
</script>

<template>
    <AppLayout>
        <Head title="Payments" />
        <PageHeader title="Payments" description="Connect Stripe so deposits go to your account." />

        <div class="max-w-xl space-y-4 rounded border border-rule bg-white p-6">
            <p v-if="status === 'not_started'" class="text-14">Not started. You can take bookings without deposits until you connect.</p>
            <p v-else-if="status === 'in_progress'" class="text-14">In progress. Stripe still needs: {{ currently_due.join(', ') || 'more details' }}.</p>
            <p v-else class="text-14">Ready. Charges are enabled on your connected account.</p>
            <p v-if="account_id" class="text-12 text-ink-2">{{ account_id }}</p>
            <Button type="button" :disabled="form.processing || status === 'ready'" @click="connect">
                {{ status === 'not_started' ? 'Connect Stripe' : 'Continue setup' }}
            </Button>
            <Link :href="route('settings.edit')" class="ml-3 text-14 underline">Back to settings</Link>
        </div>
    </AppLayout>
</template>
