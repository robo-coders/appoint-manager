<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Customers, on the shared table.
 *
 * The search is client-side because the whole list is already here — the server
 * sends every customer with their counts — and a round trip per keystroke to
 * filter a list you are already holding is a round trip for nothing. When this
 * list is big enough to paginate, the search moves to the server with it.
 *
 * The empty *search result* is a different state from the empty *table*, and
 * `bookings-table.html` is explicit about it: "No bookings match 'otto'" with a
 * way out, not the same "nothing here yet" a new salon sees.
 */
const props = defineProps<{
    customers: Array<{
        id: number;
        name: string;
        email: string;
        phone: string | null;
        subjects_count: number;
        bookings_count: number;
    }>;
}>();

const page = usePage();
const query = ref('');

const columns: Column[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', secondary: true },
    { key: 'phone', label: 'Phone', secondary: true },
    { key: 'subjects_count', label: page.props.vertical?.subject_plural ?? 'Subjects', align: 'right', numeric: true, sortable: true, width: 'staff' },
    { key: 'bookings_count', label: 'Bookings', align: 'right', numeric: true, sortable: true, width: 'staff' },
];

const rows = computed(() => {
    const needle = query.value.trim().toLowerCase();

    if (needle === '') return props.customers.map((customer) => ({ ...customer }));

    return props.customers
        .filter((customer) =>
            [customer.name, customer.email, customer.phone ?? ''].some((field) =>
                field.toLowerCase().includes(needle),
            ),
        )
        .map((customer) => ({ ...customer }));
});
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
            label="Customers"
            :row-label="(row) => `Actions for ${row.name}`"
            :empty-title="query ? `No customers match “${query}”` : 'No customers yet'"
            :empty-description="
                query
                    ? 'Search covers names, emails and phone numbers — not booking notes.'
                    : 'People appear here the first time they book, whether that is online or one you add yourself.'
            "
        >
            <template #cell:name="{ row }">
                {{ row.name }}
            </template>
            <template #cell:phone="{ row }">
                <span class="numeral">{{ row.phone ?? '—' }}</span>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="router.get(route('customers.show', Number(row.id)))">Open</MenuItem>
                <MenuItem @click="router.get(route('bookings.index'), { customer: Number(row.id) })">
                    Their bookings
                </MenuItem>
            </template>

            <template #footer>
                Showing <span class="numeral">{{ rows.length }}</span> of
                <span class="numeral">{{ customers.length }}</span>
            </template>

            <template #empty-action>
                <Button v-if="query" variant="ghost" @click="query = ''">Clear the search</Button>
            </template>
        </Table>
    </AppLayout>
</template>
