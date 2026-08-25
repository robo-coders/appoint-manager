<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    billing: {
        plan: string | null;
        status: string;
        trial_ends_at: string | null;
        trial_days_remaining: number;
        on_trial: boolean;
        read_only: boolean;
        is_comped: boolean;
        next_charge: string | null;
        payment_method: string | null;
        invoices: Array<{ id: string; date: string; amount: string; status: string; url: string | null }>;
        monthly_price: string;
        yearly_price: string;
    };
}>();

const checkout = useForm({ interval: 'monthly' });
const cancel = useForm({ reason: '' });
</script>

<template>
    <AppLayout>
        <Head title="Billing" />
        <PageHeader title="Billing" description="Your subscription. Card details stay with Stripe." />
        <div class="max-w-xl space-y-6">
            <section class="rounded border border-rule p-4 text-14">
                <p>Plan: {{ billing.is_comped ? 'Comped' : billing.plan || 'Trial' }}</p>
                <p class="text-ink-2">Status: {{ billing.status }}</p>
                <p v-if="billing.on_trial">Trial ends {{ billing.trial_ends_at }} ({{ billing.trial_days_remaining }} days left)</p>
                <p v-if="billing.next_charge">Next charge {{ billing.next_charge }}</p>
                <p v-if="billing.payment_method">{{ billing.payment_method }}</p>
                <p v-else class="text-ink-2">No card on file. You do not need one during the trial.</p>
            </section>
            <section class="flex gap-3">
                <button
                    type="button"
                    class="min-h-tap rounded bg-ink px-4 text-paper"
                    @click="checkout.interval = 'monthly'; checkout.post(route('billing.checkout'))"
                >
                    {{ billing.monthly_price }} / month
                </button>
                <button
                    type="button"
                    class="min-h-tap rounded border border-rule px-4"
                    @click="checkout.interval = 'yearly'; checkout.post(route('billing.checkout'))"
                >
                    {{ billing.yearly_price }} / year
                </button>
            </section>
            <section>
                <h2 class="font-display text-17">Invoices</h2>
                <ul class="mt-2 text-14">
                    <li v-for="invoice in billing.invoices" :key="invoice.id">
                        {{ invoice.date }} · {{ invoice.amount }} · {{ invoice.status }}
                    </li>
                    <li v-if="billing.invoices.length === 0" class="text-ink-2">None yet.</li>
                </ul>
            </section>
            <section class="space-y-3">
                <form @submit.prevent="cancel.post(route('billing.pause'))">
                    <button type="submit" class="min-h-tap underline">Pause instead of cancelling</button>
                </form>
                <form class="space-y-2" @submit.prevent="cancel.post(route('billing.cancel'))">
                    <label class="block text-13">Why are you leaving? (optional)</label>
                    <textarea v-model="cancel.reason" class="w-full" rows="3" />
                    <button type="submit" class="min-h-tap text-13 text-ink-2">Cancel subscription</button>
                </form>
            </section>
        </div>
    </AppLayout>
</template>
