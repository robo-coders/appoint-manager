<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { Head } from '@inertiajs/vue3';

/**
 * Every SMS and email the platform has sent, newest first.
 *
 * The one idea: **this screen exists to answer "did it actually go out".** So
 * the status is a column and not a word buried in a run-on muted line, and the
 * body — which is the only part that is long — is the row's own text rather
 * than something competing with the metadata for the same line.
 *
 * It was an unstyled `<ul>` of `created_at · tenant 4 · sms · sent` followed by
 * `07700900000 — Your appointment is confirmed…`, all at 13px, with the one
 * fact you came for sitting fourth in a chain of middots.
 */
defineProps<{
    messages: Array<{
        id: number;
        tenant_id: number;
        channel: string;
        type: string;
        to: string;
        status: string;
        body: string;
        created_at: string | null;
        sent_label: string;
    }>;
}>();

/*
 * `body` is a `line` at 375, and that is the point of the fix.
 *
 * The first version gave the narrow layout the timestamp and the status and
 * dropped the message — so a send log on a phone was twenty-five rows of
 * "07700900123 / 2m ago / delivered" with the thing you came to read missing
 * entirely, and every row's timestamp was the same because the seed writes them
 * in one go. The message and the time share the second line now; the time is
 * second, because it is the less interesting of the two.
 */
const columns: Column[] = [
    { key: 'sent_label', label: 'Sent', width: 'when', sortable: true },
    { key: 'status', label: 'Status', width: 'status', sortable: true, narrow: 'meta' },
    { key: 'channel', label: 'Channel', width: 'staff', sortable: true, secondary: true },
    { key: 'to', label: 'To', width: 'when', sortable: true, narrow: 'title' },
    { key: 'body', label: 'Message', narrow: 'line' },
    { key: 'tenant_id', label: 'Tenant', width: 'time', align: 'right', numeric: true, secondary: true },
];

/* Delivered is the boring answer and gets the quiet badge. Failure earns the
 * only colour on the screen. */
const toneFor = (status: string) =>
    ['failed', 'undelivered', 'bounced'].includes(status.toLowerCase()) ? 'cancelled' : 'confirmed';
</script>

<template>
    <AppLayout>
        <Head title="Send log" />

        <PageHeader
            title="Send log"
            :description="`The last ${messages.length} messages the platform sent, newest first.`"
        />

        <Table
            :columns="columns"
            :rows="messages"
            label="Sent messages"
            empty-title="Nothing sent yet"
            empty-description="Confirmations, reminders and waitlist offers appear here as they go out."
        >
            <template #cell:sent_label="{ row }">
                <span class="font-mono text-12 tabular-nums text-ink-2">{{ row.sent_label }}</span>
            </template>

            <template #cell:status="{ row }">
                <Badge :tone="toneFor(row.status)">{{ row.status }}</Badge>
            </template>

            <template #cell:to="{ row }">
                <span class="font-mono text-12 text-ink">{{ row.to }}</span>
            </template>

            <template #cell:body="{ row }">
                <span class="text-ink-2">{{ row.body }}</span>
            </template>

            <template #cell:tenant_id="{ row }">
                <span class="font-mono text-12 tabular-nums text-ink-2">{{ row.tenant_id }}</span>
            </template>
        </Table>
    </AppLayout>
</template>
