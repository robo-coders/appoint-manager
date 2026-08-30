<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import PhoneLink from '@/Components/ui/PhoneLink.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Who is due back, and what that is worth.
 *
 * The list is useful even if nobody ever turns messages on: a groomer who
 * rings her regulars needs the phone number, the due date, and a way to say
 * she called. Automatic sending is off until she reviews a dry run.
 */
type Row = {
    subject_id: number;
    subject_name: string;
    customer_name: string | null;
    phone: string | null;
    service_name: string;
    due_on: string;
    due_label: string;
    interval_label: string;
    days_overdue: number;
    price: string;
};

const props = defineProps<{
    summary: { count: number; value: string; noun: string };
    rows: Row[];
    stopped: Array<{ subject_id: number; subject_name: string; customer_name: string | null; stopped_on: string | null }>;
    messages_enabled: boolean;
    dry_run: { count: number; messages: Array<{ subject_name: string; customer_name: string | null; phone: string | null; body: string }> } | null;
    noun: string;
    noun_plural: string;
}>();

const columns: Column[] = [
    { key: 'subject_name', label: 'Name', sortable: true, narrow: 'title' },
    { key: 'customer_name', label: 'Client', sortable: true, narrow: 'line' },
    { key: 'phone', label: 'Phone', narrow: 'meta' },
    { key: 'due', label: 'Due', sortable: true, width: 'when' },
    { key: 'price', label: 'Usual', align: 'right', numeric: true, width: 'amount' },
];

const tableRows = computed(() =>
    props.rows.map((row) => ({
        ...row,
        id: row.subject_id,
        due: `${row.due_label} · ${row.interval_label}`,
    })),
);

const confirmingEnable = ref(false);
const preview = useForm({});
const enable = useForm({});
const disable = useForm({});

const startPreview = () => preview.post(route('overdue.preview-enable'));
const confirmEnable = () => {
    confirmingEnable.value = false;
    enable.post(route('overdue.enable'));
};
const call = (phone: string) => {
    window.location.assign(`tel:${phone}`);
};
</script>

<template>
    <AppLayout>
        <Head title="Overdue" />
        <PageHeader
            :title="`Overdue: ${summary.count} ${summary.noun}, ${summary.value}`"
            :description="`Who has gone past their usual interval. The date is the one to check; the interval is how it was worked out.`"
        />

        <Callout v-if="!messages_enabled && !dry_run" class="mb-6">
            Automatic messages are off. The list is yours to ring. Turning messages on starts with a dry run — nothing sends until you confirm.
            <template #action>
                <Button variant="ghost" :loading="preview.processing" @click="startPreview">Preview messages</Button>
            </template>
        </Callout>

        <Callout v-if="dry_run" class="mb-6">
            {{ dry_run.count === 0 ? 'Nobody would be contacted right now.' : `${dry_run.count} message${dry_run.count === 1 ? '' : 's'} would go out.` }}
            Nothing has been sent.
            <template #action>
                <Button @click="confirmingEnable = true">Turn messages on</Button>
            </template>
        </Callout>

        <Callout v-if="messages_enabled" class="mb-6">
            Automatic messages are on. Due {{ noun_plural }} are contacted once per cycle.
            <template #action>
                <Button variant="ghost" :loading="disable.processing" @click="disable.post(route('overdue.disable'))">Turn off</Button>
            </template>
        </Callout>

        <ol v-if="dry_run && dry_run.messages.length" class="mb-8 max-w-measure divide-y divide-rule border-y border-rule">
            <li v-for="message in dry_run.messages" :key="message.body" class="py-3">
                <p class="font-medium">{{ message.subject_name }} · {{ message.customer_name }}</p>
                <p class="mt-1 text-13 text-ink-2">{{ message.body }}</p>
            </li>
        </ol>

        <Table
            :columns="columns"
            :rows="tableRows"
            label="Overdue"
            :row-label="(row) => `Actions for ${row.subject_name}`"
            empty-title="Nobody is overdue"
            empty-description="When a regular goes past their interval, they land here."
        >
            <template #cell:phone="{ row }">
                <PhoneLink :phone="row.phone as string | null" />
            </template>
            <template #cell:due="{ row }">
                <span class="font-medium">Due {{ row.due_label }}</span>
                <span class="text-ink-2"> · {{ row.interval_label }}</span>
            </template>
            <template #cell:price="{ row }">
                <span class="numeral">{{ row.price }}</span>
            </template>
            <template #actions="{ row }">
                <MenuItem v-if="row.phone" @click="call(String(row.phone))">Call</MenuItem>
                <MenuItem @click="router.post(route('overdue.contacted', Number(row.subject_id)))">Mark contacted</MenuItem>
                <MenuItem @click="router.post(route('overdue.snooze', Number(row.subject_id)), { days: 14 })">
                    Snooze two weeks
                </MenuItem>
                <MenuItem @click="router.post(route('overdue.snooze', Number(row.subject_id)), { days: 30 })">
                    Snooze a month
                </MenuItem>
                <MenuItem danger @click="router.post(route('overdue.stop', Number(row.subject_id)))">
                    Stop chasing
                </MenuItem>
            </template>
        </Table>

        <section v-if="stopped.length" class="mt-8">
            <h2 class="border-b border-b-rule pb-3 text-17">Stopped</h2>
            <ul class="divide-y divide-rule">
                <li v-for="row in stopped" :key="row.subject_id" class="flex flex-wrap items-baseline justify-between gap-3 py-3">
                    <p>
                        <span class="font-medium">{{ row.subject_name }}</span>
                        <span class="text-ink-2"> · {{ row.customer_name }}</span>
                    </p>
                    <Button variant="ghost" @click="router.post(route('overdue.resume', row.subject_id))">Start chasing again</Button>
                </li>
            </ul>
        </section>

        <ConfirmDialog
            :show="confirmingEnable"
            title="Turn automatic messages on"
            tone="primary"
            :confirm-label="`Turn on for ${dry_run?.count ?? 0}`"
            @close="confirmingEnable = false"
            @confirm="confirmEnable"
        >
            The next daily run will send the messages you just reviewed. You can turn this off again at any time.
        </ConfirmDialog>
    </AppLayout>
</template>
