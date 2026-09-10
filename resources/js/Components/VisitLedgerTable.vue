<script setup lang="ts">
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { bookingStatusLabel, bookingStatusStruck, bookingStatusTone, bookingWhenLabel } from '@/lib/bookingStatus';
import { sentenceCase } from '@/lib/copy';
import type { Money, Paginated } from '@/types/models';
import { router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

export type LedgerRow = {
    id: number;
    service_name: string | null;
    subject_name: string | null;
    staff_name: string | null;
    starts_at_local: string | null;
    status: string;
    outcome: string;
    paid: Money;
    [key: string]: unknown;
};

const props = defineProps<{
    customerId: number;
    visits: Paginated<LedgerRow>;
    expanded: boolean;
}>();

const page = usePage();

const subjectLabel = computed(() => sentenceCase(page.props.vertical?.subject_singular ?? 'Subject'));

const columns = computed<Column[]>(() => [
    { key: 'when', label: 'When', width: 'when', narrow: 'line' },
    { key: 'service', label: 'Service', narrow: 'title' },
    { key: 'subject', label: subjectLabel.value, secondary: true },
    { key: 'staff', label: 'With', width: 'staff', secondary: true },
    { key: 'outcome', label: 'Outcome', width: 'status', narrow: 'meta' },
    { key: 'paid', label: 'Paid', width: 'amount', align: 'right', numeric: true, narrow: 'meta' },
]);

const rows = computed(() =>
    props.visits.data.map((visit) => ({
        ...visit,
        when: visit.starts_at_local ?? '',
        service: visit.service_name ?? '',
        subject: visit.subject_name ?? '',
        staff: visit.staff_name ?? '',
        outcome: visit.status,
        paid: visit.paid.amount,
        paid_formatted: visit.paid.formatted,
    })),
);

const visit = (page: number, expanded: boolean) => {
    const params: Record<string, number> = { visits: page };

    if (expanded) params.visits_all = 1;

    router.get(route('customers.show', props.customerId), params, {
        preserveState: true,
        preserveScroll: true,
        replace: true,
    });
};

const whenLabel = (value: unknown) => (value ? bookingWhenLabel(String(value)) : 'no date');

const rowHref = (row: Record<string, unknown>) => route('bookings.show', Number(row.id));

const rowLabel = (row: Record<string, unknown>) =>
    `Actions for ${String(row.service_name ?? 'this visit')}, ${whenLabel(row.starts_at_local)}`;

const paginated = computed(() => props.visits.last_page > 1);

const expandable = computed(() => props.visits.total > props.visits.per_page || props.expanded);

const toggleLabel = computed(() =>
    props.expanded ? 'Show recent only' : `Show all ${props.visits.total} visits`,
);

const toggle = () => visit(1, !props.expanded);
</script>

<template>
    <section class="rounded border border-rule bg-white p-4">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-14 font-medium">Visit ledger</h2>
            <p class="caption">
                <span class="numeral">{{ visits.from ?? 0 }}</span>–<span class="numeral">{{ visits.to ?? 0 }}</span>
                of <span class="numeral">{{ visits.total }}</span> · newest first
            </p>
        </div>

        <Table
            chrome="bare"
            :sticky="false"
            :columns="columns"
            :rows="rows"
            label="Visits"
            :row-href="rowHref"
            row-link-column="service"
            :row-class="(row) => (bookingStatusStruck(String(row.status)) ? 'opacity-80' : '')"
            :row-label="rowLabel"
            empty-title="No visits yet"
            empty-description="Nothing has been booked for this customer, so there is no history to show."
        >
            <template #cell:when="{ row }">
                <span class="numeral">{{ whenLabel(row.starts_at_local) }}</span>
            </template>

            <template #cell:service="{ row }">
                <span class="block truncate text-ink">{{ row.service_name ?? '—' }}</span>
            </template>

            <template #cell:subject="{ row }">
                <span v-if="row.subject_name" class="block truncate text-13 text-ink-2">{{ row.subject_name }}</span>
                <span v-else class="text-13 text-ink-2">—</span>
            </template>

            <template #cell:staff="{ row }">
                <span class="block truncate text-13 text-ink-2">
                    {{ String(row.staff_name ?? '—').split(' ')[0] }}
                </span>
            </template>

            <template #cell:outcome="{ row }">
                <Badge variant="solid" :tone="bookingStatusTone(String(row.status))">
                    {{ bookingStatusLabel(String(row.status)) }}
                </Badge>
            </template>

            <template #cell:paid="{ row }">
                <span :class="(row.paid as number) === 0 ? 'text-ink-3' : ''">
                    {{ row.paid_formatted }}
                </span>
            </template>

            <template #actions="{ row }">
                <MenuItem
                    @click="router.get(route('diary.index'), { date: String(row.starts_at_local ?? '').slice(0, 10) })"
                >
                    Show in the diary
                </MenuItem>
            </template>

            <template v-if="paginated || expandable" #footer-action>
                <Button v-if="expandable" variant="ghost" @click="toggle">{{ toggleLabel }}</Button>
                <Button
                    v-if="paginated"
                    variant="secondary"
                    :disabled="visits.prev_page_url === null"
                    @click="visit(visits.current_page - 1, expanded)"
                >
                    Previous
                </Button>
                <Button
                    v-if="paginated"
                    variant="secondary"
                    :disabled="visits.next_page_url === null"
                    @click="visit(visits.current_page + 1, expanded)"
                >
                    Next
                </Button>
            </template>
        </Table>
    </section>
</template>
