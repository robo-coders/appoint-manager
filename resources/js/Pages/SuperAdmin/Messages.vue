<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    messages: Array<{
        id: number;
        tenant_id: number;
        tenant_name: string | null;
        channel: string;
        type: string;
        to: string;
        status: string;
        body: string;
        created_at: string | null;
        sent_label: string;
    }>;
}>();

const columns: Column[] = [
    { key: 'sent_label', label: 'Sent', width: 'when', sortable: true },
    { key: 'status', label: 'Status', width: 'status', sortable: true, narrow: 'meta' },
    { key: 'channel', label: 'Channel', width: 'staff', sortable: true, secondary: true },
    { key: 'to', label: 'To', width: 'when', sortable: true, narrow: 'title' },
    { key: 'body', label: 'Message', narrow: 'line' },
    { key: 'tenant_name', label: 'Salon', width: 'staff', sortable: true, secondary: true },
];

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
