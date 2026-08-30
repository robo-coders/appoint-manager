<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Billing, on the component library.
 *
 * Invoices are a list, so they are the shared table with their money right and
 * mono. The plan is a summary line and a badge rather than five labelled
 * key-value pairs, because "what am I on and what happens next" is one
 * sentence, not a database dump.
 *
 * Cancelling goes through `ui/ConfirmDialog` — it was a bare submit button
 * beside a textarea, one press from ending a subscription with no confirmation
 * at all.
 */
const props = defineProps<{
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
        list_price: string;
        has_price_override: boolean;
    };
    sms: {
        used: number;
        included: number;
        prepaid: number;
        ceiling: number;
        remaining: number;
        percent: number;
        can_send: boolean;
        stopped: 'killed' | 'ceiling' | 'allowance' | null;
        warning: number | null;
        topup_price: string;
        topup_size: number;
    };
}>();

const checkout = useForm({});
const topUp = useForm({});
const cancel = useForm({ reason: '' });
const confirming = ref(false);

const subscribe = () => checkout.post(route('billing.checkout'));

const planLine = computed(() => {
    const b = props.billing;

    if (b.is_comped) return 'On the house. Nothing to pay, and nothing expires.';
    if (b.on_trial) {
        return `Trial — ${b.trial_days_remaining} day${b.trial_days_remaining === 1 ? '' : 's'} left, no card needed.`;
    }
    if (b.next_charge) return `${b.plan ?? 'Subscribed'} — next charge ${b.next_charge}.`;

    return b.plan ?? 'No subscription.';
});

const tone = computed(() => (props.billing.read_only ? 'cancelled' : props.billing.on_trial ? 'pending' : 'confirmed'));

const invoiceColumns: Column[] = [
    { key: 'date', label: 'Date', width: 'when' },
    { key: 'amount', label: 'Amount', width: 'amount', align: 'right', numeric: true },
    { key: 'status', label: 'Status', width: 'status' },
];

const invoices = computed(() => props.billing.invoices.map((invoice) => ({ ...invoice })));
</script>

<template>
    <AppLayout>
        <Head title="Billing" />
        <PageHeader title="Billing" description="Your subscription. Card details never touch this app — they stay with Stripe." />

        <div class="max-w-measure space-y-8">
            <!-- Read-only is the state that costs money to ignore, so it is the
                 first thing on the screen and it says what still works. -->
            <Callout v-if="billing.read_only" tone="danger" title="The diary is read-only">
                Your booking page is still live and still taking bookings — nothing has been lost. Subscribing turns
                writing back on immediately.
            </Callout>

            <section>
                <div class="flex flex-wrap items-baseline justify-between gap-3 border-b border-b-rule pb-3">
                    <h2 class="text-17">Plan</h2>
                    <Badge :tone="tone">{{ billing.status }}</Badge>
                </div>
                <p class="mt-3 text-14">{{ planLine }}</p>
                <p class="mt-1 text-13 text-ink-2">
                    {{ billing.payment_method ?? 'No card on file. You do not need one during the trial.' }}
                </p>

                <div class="mt-4 flex flex-wrap gap-3">
                    <Button :loading="checkout.processing" @click="subscribe">
                        {{ billing.monthly_price }} a month
                    </Button>
                </div>
                <p v-if="billing.has_price_override" class="mt-2 text-13 text-ink-2">
                    Your rate. The list price is {{ billing.list_price }} a month.
                </p>
            </section>

            <section>
                <h2 class="border-b border-b-rule pb-3 text-17">Texts</h2>
                <p class="mt-3 text-14">
                    <span class="numeral font-medium">{{ sms.used }}</span>
                    of
                    <span class="numeral">{{ sms.included }}</span>
                    included this cycle
                    <span v-if="sms.prepaid > 0">
                        · <span class="numeral">{{ sms.prepaid }}</span> bought, still to use
                    </span>
                </p>
                <p class="mt-1 text-13 text-ink-2">
                    Email is not counted. A top-up is {{ sms.topup_price }} for
                    <span class="numeral">{{ sms.topup_size }}</span> more, applied immediately, and it does not expire
                    with the cycle.
                </p>
                <Callout v-if="sms.stopped === 'ceiling'" class="mt-4" tone="danger" title="Send limit reached">
                    SMS has stopped. A top-up cannot lift this. Email still goes out, and you can still ring people.
                </Callout>
                <Callout v-else-if="sms.stopped === 'allowance'" class="mt-4" tone="accent" title="Included texts used up">
                    SMS has stopped until you buy more or the cycle resets. Email still goes out.
                </Callout>
                <Callout v-else-if="sms.warning === 80" class="mt-4" title="Most of this cycle's texts are used">
                    You have used {{ sms.used }} of {{ sms.included }}. Nothing has stopped yet.
                </Callout>
                <div class="mt-4">
                    <Button
                        variant="secondary"
                        :loading="topUp.processing"
                        :disabled="sms.stopped === 'ceiling' || sms.stopped === 'killed'"
                        @click="topUp.post(route('billing.top-up'))"
                    >
                        Buy {{ sms.topup_size }} more for {{ sms.topup_price }}
                    </Button>
                </div>
            </section>

            <section>
                <h2 class="border-b border-b-rule pb-3 text-17">Invoices</h2>
                <div class="mt-4">
                    <Table
                        :columns="invoiceColumns"
                        :rows="invoices"
                        label="Invoices"
                        empty-title="No invoices yet"
                        empty-description="The first one arrives when the trial ends and the first payment goes through."
                    >
                        <template #cell:date="{ row }"><span class="numeral">{{ row.date }}</span></template>
                        <template #cell:status="{ row }">
                            <Badge :tone="row.status === 'paid' ? 'confirmed' : 'pending'">{{ row.status }}</Badge>
                        </template>
                    </Table>
                </div>
            </section>

            <section>
                <h2 class="border-b border-b-rule pb-3 text-17">Leaving</h2>
                <p class="mt-3 text-14 text-ink-2">
                    Pausing keeps your diary readable and stops the charges. Cancelling ends the subscription; your
                    booking page stays up either way.
                </p>

                <div class="mt-4 space-y-3">
                    <Textarea
                        v-model="cancel.reason"
                        label="Why are you leaving?"
                        :rows="3"
                        hint="Optional, and read by a person."
                        :error="cancel.errors.reason"
                    />
                    <div class="flex flex-wrap items-center gap-4">
                        <Button variant="secondary" :loading="cancel.processing" @click="cancel.post(route('billing.pause'))">
                            Pause instead
                        </Button>
                        <QuietAction @click="confirming = true">Cancel the subscription</QuietAction>
                    </div>
                </div>
            </section>
        </div>

        <ConfirmDialog
            :show="confirming"
            title="Cancel your subscription?"
            confirm-label="Yes, cancel it"
            cancel-label="Keep it"
            :loading="cancel.processing"
            @close="confirming = false"
            @confirm="cancel.post(route('billing.cancel'), { onSuccess: () => (confirming = false) })"
        >
            Your diary becomes read-only at the end of the period you have paid for. Your booking page stays live and
            keeps taking bookings, and nothing is deleted.
        </ConfirmDialog>
    </AppLayout>
</template>
