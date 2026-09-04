<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { Money } from '@/types/models';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive } from 'vue';

/**
 * Bookings. `public/mockups/bookings-table.html` is the binding target, and the
 * whole screen is `ui/Table` — sortable, sticky header, hairline rows, no
 * zebra, money right-aligned in mono, and one actions menu per row rather than
 * five inline links, which is what keeps a 34px row at 34px.
 *
 * The filters were three hand-rolled form controls with no error binding; they
 * are `ui/Select` and `ui/TextInput` now, which is the whole reason the
 * component library exists.
 *
 * (Written that way on purpose: `check:components` reads raw text, so a comment
 * naming the tags it forbids trips it. Bluntness is the point of that check —
 * a rule with an exception for comments is a rule with an exception.)
 */

type BookingRow = {
    id: number;
    customer_name: string;
    subject_name: string | null;
    service_name: string;
    staff_name: string;
    starts_at_local: string;
    status: string;
    source: string;
    price_at_booking: Money;
    [key: string]: unknown;
};

const props = defineProps<{
    filters: { status: string; from: string; to: string };
    bookings: BookingRow[];
}>();

const filters = reactive({ ...props.filters });

const apply = () => router.get(route('bookings.index'), { ...filters }, { preserveState: true, replace: true });

/*
 * `narrow` is the phone layout. At 375px this table put the amount and the row
 * menu off the right-hand edge behind a horizontal scroll — the "Amo…" and "£."
 * that a screenshot at that width shows — while "Sep 16 15:30" broke into three
 * lines in a 152px column. The row is a list item there instead: who it is on
 * top, the appointment underneath, the money hard right. See `ui/Table`.
 *
 * The second line takes the columns in the order they are declared here, which
 * is why `when` leads it: on a phone the question is "when is this", and the
 * customer is already the headline.
 */
const columns: Column[] = [
    { key: 'when', label: 'When', width: 'when', sortable: true, narrow: 'line' },
    { key: 'customer', label: 'Customer', sortable: true, narrow: 'title' },
    { key: 'service', label: 'Service', secondary: true, narrow: 'line' },
    // Not in the narrow row. Four parts on the second line wrapped to three,
    // and the groomer was the part that ended up alone on the last one. It is
    // already `secondary`, so a phone never showed it in the table either.
    { key: 'staff', label: 'Staff', width: 'staff', secondary: true },
    { key: 'status', label: 'Status', width: 'status', narrow: 'meta' },
    {
        key: 'amount',
        label: 'Amount',
        width: 'amount',
        align: 'right',
        numeric: true,
        sortable: true,
        narrow: 'meta',
    },
];

/*
 * Sorting is the table's own, over rows the server has already narrowed to the
 * filtered range — so the shape the rows are sorted into is `sortable`, and
 * `when` sorts on the raw local timestamp rather than on "10 Mar 09:00", which
 * would sort alphabetically and put March before February.
 */
const rows = computed(() =>
    props.bookings.map((booking) => ({
        ...booking,
        when: booking.starts_at_local,
        customer: booking.customer_name,
        service: booking.service_name,
        staff: booking.staff_name,
        status: booking.status,
        amount: booking.price_at_booking.amount,
    })),
);

const STATUS_LABELS: Record<string, string> = {
    pending: 'Awaiting deposit',
    confirmed: 'Confirmed',
    cancelled: 'Cancelled',
    declined: 'Declined',
    completed: 'Completed',
    no_show: 'No show',
};

const toneFor = (status: string) =>
    status === 'cancelled' ? 'cancelled' : status === 'confirmed' || status === 'completed' ? 'confirmed' : 'pending';

/** "10 Mar 09:00" from "2026-03-10 09:00". */
const whenLabel = (value: string) => {
    const date = new Date(`${value.replace(' ', 'T')}:00`);

    return Number.isNaN(date.getTime())
        ? value
        : `${date.toLocaleDateString(undefined, { day: 'numeric', month: 'short' })} ${value.slice(11)}`;
};

const rowLabel = (row: Record<string, unknown>) =>
    `Actions for ${row.customer_name}, ${whenLabel(String(row.starts_at_local))}`;
</script>

<template>
    <AppLayout>
        <Head title="Bookings" />
        <PageHeader title="Bookings" description="Everything booked, filtered by status and date.">
            <Button @click="router.get(route('diary.index'), { new: 1 })">New booking</Button>
        </PageHeader>

        <form class="mb-6 grid items-end gap-3 md:grid-cols-4" @submit.prevent="apply">
            <Select
                v-model="filters.status"
                label="Status"
                :options="[
                    { value: '', label: 'All statuses' },
                    { value: 'pending', label: 'Awaiting deposit' },
                    { value: 'confirmed', label: 'Confirmed' },
                    { value: 'cancelled', label: 'Cancelled' },
                    { value: 'declined', label: 'Declined' },
                    { value: 'completed', label: 'Completed' },
                    { value: 'no_show', label: 'No show' },
                ]"
            />
            <TextInput v-model="filters.from" type="date" label="From" />
            <TextInput v-model="filters.to" type="date" label="To" />
            <Button variant="secondary" type="submit">Apply filters</Button>
        </form>

        <Table
            :columns="columns"
            :rows="rows"
            label="Bookings"
            :row-label="rowLabel"
            empty-title="No bookings in this range"
            empty-description="Widen the dates, clear the status filter, or open the diary and add one."
        >
            <template #cell:when="{ row }">
                <span class="numeral">{{ whenLabel(String(row.starts_at_local)) }}</span>
            </template>

            <template #cell:customer="{ row }">
                {{ row.customer_name }}
                <span v-if="row.subject_name" class="text-ink-2">· {{ row.subject_name }}</span>
            </template>

            <!-- First names. `--col-staff` is 96px and "Marek Kowalski" wraps
                 to two lines in it, which turns a 34px row into a 48px one;
                 the mockup shows "Ana" and "Marek" for the same reason. -->
            <template #cell:staff="{ row }">
                <span class="block truncate text-13 text-ink-2">{{ String(row.staff_name ?? '').split(' ')[0] }}</span>
            </template>

            <!-- Status is text first: the dot is decorative, the label is the
                 meaning, so it survives greyscale and a screen reader alike. -->
            <template #cell:status="{ row }">
                <Badge :tone="toneFor(String(row.status))">{{ STATUS_LABELS[String(row.status)] ?? row.status }}</Badge>
            </template>

            <template #cell:amount="{ row }">
                <span :class="row.status === 'cancelled' ? 'text-ink-2' : ''">
                    {{ (row.price_at_booking as Money).formatted }}
                </span>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="router.get(route('bookings.show', Number(row.id)))">Open</MenuItem>
                <MenuItem @click="router.get(route('diary.index'), { date: String(row.starts_at_local).slice(0, 10) })">
                    Show in the diary
                </MenuItem>
            </template>

            <template #footer>
                Showing <span class="numeral">{{ rows.length }}</span>
                booking{{ rows.length === 1 ? '' : 's' }}
            </template>

            <template #empty-action>
                <Button variant="ghost" @click="router.get(route('bookings.index'))">Clear the filters</Button>
            </template>
        </Table>
    </AppLayout>
</template>
