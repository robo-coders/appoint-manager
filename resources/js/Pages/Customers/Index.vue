<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import PhoneLink from '@/Components/ui/PhoneLink.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { sentenceCase } from '@/lib/copy';
import type { Paginated } from '@/types/models';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

/**
 * Customers, on the shared table.
 *
 * Search, sort and page all go to the server. The list is no longer loaded
 * whole, so a local filter would only see the current page.
 *
 * The empty *search result* is a different state from the empty *table*, and
 * `bookings-table.html` is explicit about it: "No bookings match 'otto'" with a
 * way out, not the same "nothing here yet" a new salon sees.
 *
 * **On a phone this is a list, not a table**, and that is what `narrow` on the
 * columns below buys. This screen was never rebuilt for a narrow viewport, so
 * nobody had designed its narrow state and it showed: at 375px the names broke
 * across two lines ("Ade / Oyelaran"), the rows went ragged as some wrapped and
 * some did not, and email and phone — both `secondary`, so both hidden below md
 * — were simply gone, with no way to reach either. A salon owner looking
 * somebody up on their phone between dogs is a primary user of this screen, and
 * the thing they need most was the thing that had been dropped.
 *
 * So the narrow row is: the name, the email under it, and the phone number hard
 * right as a `tel:` link. See `ui/Table`, "the narrow state", and the note on the
 * columns below for why the two contact details are split rather than sharing a
 * line.
 */
type CustomerRow = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    subjects_count: number;
    bookings_count: number;
};

const props = defineProps<{
    filters: { search: string; sort: string; direction: 'asc' | 'desc' };
    customers: Paginated<CustomerRow>;
}>();

const page = usePage();
const query = ref(props.filters.search);

const visit = (overrides: Record<string, string | number> = {}) =>
    router.get(route('customers.index'), { ...props.filters, search: query.value, ...overrides }, { preserveState: true, replace: true });

const onSort = (next: { key: string; direction: 'asc' | 'desc' }) =>
    visit({ sort: next.key, direction: next.direction, page: 1 });

let searchWait: ReturnType<typeof setTimeout> | undefined;

watch(query, (value) => {
    if (searchWait) clearTimeout(searchWait);
    searchWait = setTimeout(() => visit({ search: value, page: 1 }), 300);
});

onUnmounted(() => {
    if (searchWait) clearTimeout(searchWait);
});

/*
 * `subject_plural` is 'dogs' in config/verticals.php — lower case, because most
 * of its uses are mid-sentence. As a column header on its own it sat between
 * "Name" and "Bookings" in lower case and read like a bug. Sentence-cased at the
 * point of render rather than in the config, so every vertical gets it and none
 * of them lose the lower-case form their sentences need. See `lib/copy`.
 */
const subjectPlural = computed(() => sentenceCase(page.props.vertical?.subject_plural ?? 'Subjects'));

/*
 * The narrow row is name / email, with the phone number hard right.
 *
 * Both contact details on the second line was the first attempt and it was
 * ragged: "07426124639 · ade.oyelaran20@example.test" is a few pixels wider than
 * the second line has once the right-hand column has taken its share, so the
 * long ones wrapped and the short ones did not, and the rows went two lines,
 * three lines, two lines down the list — the same unevenness the table version
 * had, arrived at a different way.
 *
 * Splitting them fixes it and puts the right thing in the right place: the
 * number is the *actionable* half, it is a fixed 11 characters in mono so it
 * always fits, and hard right at the end of the row it is both a tap target and
 * the thing your thumb is already near. Every row is exactly two lines.
 *
 * Phone before email in the list is also one place different in the wide table,
 * which is deliberate: the number is what this screen is used for.
 */
const columns = computed<Column[]>(() => [
    { key: 'name', label: 'Name', sortable: true, narrow: 'title' },
    { key: 'phone', label: 'Phone', secondary: true, narrow: 'meta' },
    { key: 'email', label: 'Email', secondary: true, narrow: 'line' },
    {
        key: 'subjects_count',
        label: subjectPlural.value,
        align: 'right',
        numeric: true,
        sortable: true,
        width: 'staff',
    },
    // Not in the narrow row. "Booked 7 times" is a thing you read while
    // deciding something at a desk; it is not why anybody opens this on a phone,
    // and it was competing for the width the phone number needs.
    { key: 'bookings_count', label: 'Bookings', align: 'right', numeric: true, sortable: true, width: 'staff' },
]);

const rows = computed(() => props.customers.data.map((customer) => ({ ...customer })));
</script>

<template>
    <AppLayout>
        <Head title="Customers" />
        <PageHeader title="Customers" description="Everyone who has booked with you." />

        <div class="mb-4 max-w-col-when">
            <TextInput v-model="query" label="Search" placeholder="Name, email or phone" />
        </div>

        <Table
            :columns="columns"
            :rows="rows"
            :sort="{ key: filters.sort, direction: filters.direction }"
            label="Customers"
            :row-label="(row) => `Actions for ${row.name}`"
            :empty-title="filters.search ? `No customers match “${filters.search}”` : 'No customers yet'"
            :empty-description="
                filters.search
                    ? 'Search covers names, emails and phone numbers — not booking notes.'
                    : 'People appear here the first time they book, whether that is online or one you add yourself.'
            "
            @sort="onSort"
        >
            <template #cell:name="{ row }">
                {{ row.name }}
            </template>

            <!--
                One tap, not a menu.

                The number was plain text in a column that a phone did not show
                at all, so calling a customer back meant opening the row menu,
                opening their record, and reading a number off the screen to dial
                by hand. `ui/PhoneLink` is the whole distance between "reachable"
                and "one tap".
            -->
            <template #cell:phone="{ row }">
                <PhoneLink :phone="row.phone as string | null" />
            </template>

            <template #actions="{ row }">
                <MenuItem @click="router.get(route('customers.show', Number(row.id)))">Open</MenuItem>
                <MenuItem @click="router.get(route('bookings.index'), { customer: Number(row.id) })">
                    Their bookings
                </MenuItem>
            </template>

            <template #footer>
                Showing
                <span class="numeral">{{ customers.from ?? 0 }}</span>–<span class="numeral">{{ customers.to ?? 0 }}</span>
                of <span class="numeral">{{ customers.total }}</span>
            </template>

            <template #empty-action>
                <Button v-if="filters.search" variant="ghost" @click="query = ''">Clear the search</Button>
            </template>
        </Table>

        <div v-if="customers.last_page > 1" class="mt-2 flex gap-2">
            <Button
                variant="secondary"
                :disabled="customers.prev_page_url === null"
                @click="visit({ page: customers.current_page - 1 })"
            >
                Previous
            </Button>
            <Button
                variant="secondary"
                :disabled="customers.next_page_url === null"
                @click="visit({ page: customers.current_page + 1 })"
            >
                Next
            </Button>
        </div>
    </AppLayout>
</template>
