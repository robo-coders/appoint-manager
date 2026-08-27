<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { Head } from '@inertiajs/vue3';

/**
 * What broke. Two lists, because they break for different reasons and are read
 * at different moments.
 *
 * The one idea: **an empty failures screen has to say "nothing is broken" and
 * mean it.** This page used to render `JSON.stringify(failed_jobs, null, 2)`
 * into a `<pre>` — so with no failures it printed `[]`, which is indistinguishable
 * from a screen that has not loaded, and with failures it printed several
 * hundred lines of serialised PHP closure in 12px with the exception message
 * somewhere inside it.
 *
 * The exception and its class are columns now, and the payload is behind the
 * row rather than in front of it: `failed_jobs.payload` is a serialised job and
 * nobody reads one at 2am, they read the message and go and look at the code.
 */
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

/*
 * The exception is the `line` at 375, and that is the point.
 *
 * The first version gave the narrow layout the job name and the timestamp and
 * dropped the exception class and the message — so this screen on a phone said
 * `App\Jobs\SendBookingReminder / 3h ago` and nothing whatsoever about what
 * went wrong, which is the only reason anybody opens it. The name tells you
 * where to look; the message tells you whether you need to.
 */
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

        <!--
            "Nothing is broken" is a designed state, and it is the one this
            screen is in almost every time it is opened. `[]` in a monospace box
            is not that sentence.
        -->
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
