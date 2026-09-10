<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import {
    BOOKING_STATUS_LABELS as STATUS_LABELS,
    bookingStatusStruck as struck,
    bookingStatusTone as toneFor,
    bookingWhenLabel as whenLabel,
    depositStatusLabel,
} from '@/lib/bookingStatus';
import type { Money, Paginated } from '@/types/models';
import { Head, router } from '@inertiajs/vue3';
import { computed, reactive, ref, watch } from 'vue';

/**
 * Bookings. `.design/mockups/backend/DiaryDesk operator redesign` is the
 * binding target, and the whole screen is `ui/Table` — sortable, sticky header,
 * hairline rows, no zebra, money right-aligned in mono, and one actions menu per
 * row rather than five inline links, which is what keeps a 34px row at 34px.
 *
 * The status filter was a `ui/Select` she had to open, read and apply. It is a
 * tab strip now, and the counts come from the server so it says what each one
 * holds before she presses it — which is the whole reason to have four of them
 * rather than a list of six. The tabs answer the same question the Select did
 * and they visit the same URL, so a bookmarked `?status=completed` still works;
 * a status with no tab of its own gets one appended rather than leaving the
 * strip with nothing selected.
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
    deposit_status: string;
    duration_minutes: number | null;
    price_at_booking: Money;
    [key: string]: unknown;
};

type Counts = Record<string, number>;

const props = defineProps<{
    filters: { status: string; from: string; to: string; sort: string; direction: 'asc' | 'desc' };
    counts: Counts;
    bookings: Paginated<BookingRow>;
}>();

const filters = reactive({ ...props.filters });

const visit = (overrides: Record<string, string | number> = {}) =>
    router.get(route('bookings.index'), { ...filters, ...overrides }, { preserveState: true, replace: true });

const apply = () => visit({ page: 1 });

const onSort = (next: { key: string; direction: 'asc' | 'desc' }) => {
    filters.sort = next.key;
    filters.direction = next.direction;
    visit({ sort: next.key, direction: next.direction, page: 1 });
};

/*
 * The four she actually sorts by. Declined, completed and no-show are on All
 * and nowhere else: a tab per status is six tabs, and three of them would read
 * zero on almost every salon on almost every day.
 */
const TABS = ['', 'confirmed', 'pending', 'cancelled'];

const status = ref(props.filters.status);

watch(
    () => props.filters.status,
    (next) => (status.value = next),
);

watch(status, (next) => {
    if (next === filters.status) return;
    filters.status = next;
    apply();
});

const tabs = computed(() => {
    const known = TABS.includes(props.filters.status) ? TABS : [...TABS, props.filters.status];

    return known.map((value) => ({
        value,
        label: value === '' ? 'All' : (STATUS_LABELS[value] ?? value),
        count: value === '' ? (props.counts.total ?? 0) : (props.counts[value] ?? 0),
    }));
});

const exportHref = computed(() =>
    route('bookings.export', {
        status: filters.status,
        from: filters.from,
        to: filters.to,
        sort: filters.sort,
        direction: filters.direction,
    }),
);

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
    { key: 'when', label: 'When', width: 'when', sortable: true, narrow: 'lead' },
    { key: 'customer', label: 'Customer', sortable: true, narrow: 'title' },
    { key: 'service', label: 'Service', secondary: true, narrow: 'line' },
    { key: 'deposit', label: 'Deposit', narrowOnly: true, narrow: 'line' },
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
 * The table no longer sorts these itself. `sort` is passed through so a click
 * asks the server for the next page of that order — the same contract as the
 * status and date filters above.
 */
const rows = computed(() =>
    props.bookings.data.map((booking) => ({
        ...booking,
        when: booking.starts_at_local,
        customer: booking.customer_name,
        service: booking.service_name,
        staff: booking.staff_name,
        status: booking.status,
        deposit: depositStatusLabel(booking.deposit_status),
        amount: booking.price_at_booking.amount,
    })),
);

const rowLabel = (row: Record<string, unknown>) =>
    `Actions for ${row.customer_name}, ${whenLabel(String(row.starts_at_local))}`;

/*
 * The row goes to the record. The redesign draws every bookings row as one
 * click target and this list is the reason `ui/Table` grew `rowHref` — it had
 * "Open" as the first item of the `⋯` menu, which is two clicks and a read to
 * do the only thing anybody comes to this screen for, on a row that already lit
 * up under the pointer and then did nothing when pressed.
 *
 * The menu keeps what the row cannot say: where else to look at this booking,
 * and the one destructive thing. Nothing in it duplicates the row any more.
 */
const rowHref = (row: Record<string, unknown>) => route('bookings.show', Number(row.id));

/** The row being cancelled, or null. Confirmed before it is sent — see `ConfirmDialog`. */
const cancelling = ref<BookingRow | null>(null);

const cancel = () => {
    const booking = cancelling.value;
    if (booking === null) return;

    router.delete(route('bookings.destroy', booking.id), {
        data: { offer_waitlist: true },
        preserveScroll: true,
        onFinish: () => (cancelling.value = null),
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Bookings" />
        <PageHeader title="Bookings" description="Everything booked, filtered by status and date.">
            <Button variant="secondary" :href="exportHref">Export</Button>
            <Button @click="router.get(route('diary.index'), { new: 1 })">New booking</Button>
        </PageHeader>

        <!--
            The filter strip, and the dates on the same line as it. The redesign
            draws one row of filters over the table with the date control pushed
            hard right against the same rule; this screen had the row and then a
            separate labelled form under it, which put 90px of chrome between the
            heading and the first booking to hold two fields that are usually
            empty. The labels move to `aria-label` — "From" and "To" either side
            of an en dash is already the sentence, and a visible label per field
            is what forced the second row.
        -->
        <Tabs v-model="status" variant="filter" :tabs="tabs" label="Filter bookings by status">
            <template #end>
                <!--
                    `w-full … sm:w-auto` and `flex-wrap` are what keep a 375px
                    phone off a horizontal scrollbar. Two 152px date fields, a
                    separator and a button are about 416px of controls, and a
                    375px viewport has 343px of room — put on one unshrinkable
                    line they pushed the *document* wider than the window, which
                    takes the header, the table and the footer sideways with it.
                    `tests/e2e/screens.spec.ts` asserts exactly that and caught
                    this. On a phone they stack; from 640 up they are the one line
                    the redesign draws.
                -->
                <form class="flex w-full flex-wrap items-center gap-2 sm:w-auto" @submit.prevent="apply">
                    <TextInput
                        v-model="filters.from"
                        type="date"
                        label="From"
                        label-hidden
                        class="w-full sm:w-col-when"
                    />
                    <span aria-hidden="true" class="hidden text-13 text-ink-2 sm:block">–</span>
                    <TextInput v-model="filters.to" type="date" label="To" label-hidden class="w-full sm:w-col-when" />
                    <Button variant="secondary" type="submit">Apply</Button>
                </form>
            </template>
        </Tabs>

        <Table
            chrome="bare"
            :columns="columns"
            :rows="rows"
            :sort="{ key: filters.sort, direction: filters.direction }"
            label="Bookings"
            :row-href="rowHref"
            row-link-column="customer"
            :row-class="(row) => (struck(String(row.status)) ? 'opacity-80' : '')"
            :row-label="rowLabel"
            empty-title="No bookings in this range"
            empty-description="Widen the dates, clear the status filter, or open the diary and add one."
            @sort="onSort"
        >
            <template #cell:when="{ row }">
                <span class="numeral">{{ whenLabel(String(row.starts_at_local)) }}</span>
            </template>

            <template #narrow:when="{ row }">
                <span class="numeral block text-17 font-medium text-ink">
                    {{ String(row.starts_at_local).slice(11) }}
                </span>
                <span v-if="row.duration_minutes" class="numeral mt-px block text-12 text-ink-2">
                    {{ row.duration_minutes }}m
                </span>
            </template>

            <!-- Who it is, then whose pet. The name is the thing being scanned
                 down this column, so it carries the row's weight and the animal
                 sits under it rather than beside it — a middot between two names
                 makes one string of them and neither is found any faster. -->
            <template #cell:customer="{ row }">
                <span class="block truncate font-medium text-ink">{{ row.customer_name }}</span>
                <span v-if="row.subject_name" class="mt-px block truncate text-12 text-ink-2">
                    {{ row.subject_name }}
                </span>
            </template>

            <!-- First names. `--col-staff` is 96px and "Marek Kowalski" wraps
                 to two lines in it, which turns a 34px row into a 48px one;
                 the mockup shows "Ana" and "Marek" for the same reason. -->
            <template #cell:staff="{ row }">
                <span class="block truncate text-13 text-ink-2">{{ String(row.staff_name ?? '').split(' ')[0] }}</span>
            </template>

            <!-- Status is text first: the fill is the mark, the label is the
                 meaning, so it survives greyscale and a screen reader alike. -->
            <template #cell:status="{ row }">
                <Badge variant="solid" :tone="toneFor(String(row.status))">
                    {{ STATUS_LABELS[String(row.status)] ?? row.status }}
                </Badge>
            </template>

            <template #cell:amount="{ row }">
                <span :class="struck(String(row.status)) ? 'text-ink-3 line-through' : ''">
                    {{ (row.price_at_booking as Money).formatted }}
                </span>
            </template>

            <!--
                Secondary actions only. "Open" is gone from here because the row
                *is* open — see `rowHref` above — and a menu whose first item
                repeats the control it sits inside teaches that the control does
                not work.
            -->
            <template #actions="{ row }">
                <MenuItem @click="router.get(route('diary.index'), { date: String(row.starts_at_local).slice(0, 10) })">
                    Show in the diary
                </MenuItem>
                <MenuItem
                    v-if="!['cancelled', 'declined', 'completed', 'no_show'].includes(String(row.status))"
                    danger
                    @click="cancelling = row as unknown as BookingRow"
                >
                    Cancel booking
                </MenuItem>
            </template>

            <template #footer>
                Showing
                <span class="numeral">{{ bookings.from ?? 0 }}</span>–<span class="numeral">{{ bookings.to ?? 0 }}</span>
                of <span class="numeral">{{ bookings.total }}</span>
            </template>

            <!-- Hard right on the table's own closing rule, which is where the
                 redesign puts "Load more". -->
            <template v-if="bookings.last_page > 1" #footer-action>
                <Button
                    variant="secondary"
                    :disabled="bookings.prev_page_url === null"
                    @click="visit({ page: bookings.current_page - 1 })"
                >
                    Previous
                </Button>
                <Button
                    variant="secondary"
                    :disabled="bookings.next_page_url === null"
                    @click="visit({ page: bookings.current_page + 1 })"
                >
                    Next
                </Button>
            </template>

            <template #empty-action>
                <Button variant="ghost" @click="router.get(route('bookings.index'))">Clear the filters</Button>
            </template>
        </Table>

        <ConfirmDialog
            :show="cancelling !== null"
            title="Cancel this booking?"
            confirm-label="Cancel and text the waitlist"
            :body="
                cancelling
                    ? `${cancelling.customer_name} — ${cancelling.service_name}, ${whenLabel(cancelling.starts_at_local)}. This frees the slot and offers it to anybody waiting.`
                    : undefined
            "
            @close="cancelling = null"
            @confirm="cancel"
        />
    </AppLayout>
</template>
