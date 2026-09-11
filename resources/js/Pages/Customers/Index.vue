<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import HiddenContact from '@/Components/ui/HiddenContact.vue';
import PhoneLink from '@/Components/ui/PhoneLink.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { sentenceCase } from '@/lib/copy';
import type { Paginated } from '@/types/models';
import { Head, router, usePage } from '@inertiajs/vue3';
import { computed, onUnmounted, ref, watch } from 'vue';

type CustomerRow = {
    id: number;
    name: string;
    email: string | null;
    phone: string | null;
    has_email: boolean;
    contact_hidden: boolean;
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

const subjectPlural = computed(() => sentenceCase(page.props.vertical?.subject_plural ?? 'Subjects'));

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
    { key: 'bookings_count', label: 'Bookings', align: 'right', numeric: true, sortable: true, width: 'staff' },
]);

const rows = computed(() => props.customers.data.map((customer) => ({ ...customer })));

const rowHref = (row: Record<string, unknown>) => route('customers.show', Number(row.id));
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
            :row-href="rowHref"
            row-link-column="name"
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

            <template #cell:phone="{ row }">
                <HiddenContact v-if="row.contact_hidden && row.phone" :masked="row.phone as string" />
                <PhoneLink v-else :phone="row.phone as string | null" />
            </template>

            <template #cell:email="{ row }">
                <HiddenContact v-if="row.contact_hidden && row.has_email" />
                <span v-else-if="row.email">{{ row.email }}</span>
                <span v-else class="text-ink-2">—</span>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="router.get(rowHref(row))">Their bookings</MenuItem>
            </template>

            <template #footer>
                Showing
                <span class="numeral">{{ customers.from ?? 0 }}</span>–<span class="numeral">{{ customers.to ?? 0 }}</span>
                of <span class="numeral">{{ customers.total }}</span>
            </template>

            <template v-if="customers.last_page > 1" #footer-action>
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
            </template>

            <template #empty-action>
                <Button v-if="filters.search" variant="ghost" @click="query = ''">Clear the search</Button>
            </template>
        </Table>
    </AppLayout>
</template>
