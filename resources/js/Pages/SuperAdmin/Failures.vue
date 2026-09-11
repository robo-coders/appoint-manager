<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    failed_jobs: Array<{
        id: number;
        queue: string;
        job_name: string;
        exception_class: string;
        exception_message: string;
        failed_label: string;
    }>;
    webhook_failures: Array<{
        id: number;
        source: string;
        type: string | null;
        message: string;
        received_label: string;
    }>;
}>();

const jobColumns: Column[] = [
    { key: 'failed_label', label: 'Failed', width: 'when', sortable: true, narrow: 'meta' },
    { key: 'job_name', label: 'Job', sortable: true, narrow: 'title' },
    { key: 'exception_class', label: 'Exception', sortable: true, secondary: true, narrow: 'line' },
    { key: 'exception_message', label: 'Message', narrow: 'line' },
    { key: 'queue', label: 'Queue', width: 'staff', sortable: true, secondary: true },
];

const hookColumns: Column[] = [
    { key: 'received_label', label: 'Received', width: 'when', sortable: true, narrow: 'meta' },
    { key: 'source', label: 'Source', width: 'staff', sortable: true, narrow: 'title' },
    { key: 'type', label: 'Type', width: 'when', sortable: true, narrow: 'line' },
    { key: 'message', label: 'Message', narrow: 'line' },
];
</script>

<template>
    <AppLayout>
        <Head title="Failures" />

        <PageHeader title="Failures" description="Queue jobs and webhooks that did not get through." />

        <EmptyState
            v-if="failed_jobs.length === 0 && webhook_failures.length === 0"
            title="Nothing has failed"
            description="No queue job and no webhook has failed. This is the state this screen should normally be in."
        />

        <template v-else>
            <section>
                <h2 class="mb-2 text-15">
                    Queue jobs
                    <span class="ml-1 font-mono text-13 tabular-nums text-ink-2">{{ failed_jobs.length }}</span>
                </h2>
                <Table
                    :columns="jobColumns"
                    :rows="failed_jobs"
                    label="Failed queue jobs"
                    empty-title="No failed jobs"
                    empty-description="Everything the queue has been handed has gone through."
                >
                    <template #cell:failed_label="{ row }">
                        <span class="font-mono text-12 tabular-nums text-ink-2">{{ row.failed_label }}</span>
                    </template>
                    <template #cell:job_name="{ row }">
                        <span class="break-all font-mono text-12 text-ink">{{ row.job_name }}</span>
                    </template>
                    <template #cell:exception_class="{ row }">
                        <span class="break-all font-mono text-12 text-danger">{{ row.exception_class }}</span>
                    </template>
                    <template #cell:exception_message="{ row }">
                        <span class="text-ink-2">{{ row.exception_message }}</span>
                    </template>
                </Table>
            </section>

            <section class="mt-12">
                <h2 class="mb-2 text-15">
                    Webhooks
                    <span class="ml-1 font-mono text-13 tabular-nums text-ink-2">{{ webhook_failures.length }}</span>
                </h2>
                <Table
                    :columns="hookColumns"
                    :rows="webhook_failures"
                    label="Failed webhooks"
                    empty-title="No webhook failures"
                    empty-description="Every webhook that arrived was accepted and verified."
                >
                    <template #cell:received_label="{ row }">
                        <span class="font-mono text-12 tabular-nums text-ink-2">{{ row.received_label }}</span>
                    </template>
                    <template #cell:type="{ row }">
                        <span class="break-all font-mono text-12 text-ink">{{ row.type ?? '—' }}</span>
                    </template>
                    <template #cell:message="{ row }">
                        <span class="text-ink-2">{{ row.message }}</span>
                    </template>
                </Table>
            </section>
        </template>
    </AppLayout>
</template>
