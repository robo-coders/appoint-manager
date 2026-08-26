<script setup lang="ts">
import { DEFAULT_STAFF_COLOUR } from '@/lib/staffColour';
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import StaffColourField from '@/Components/ui/StaffColourField.vue';
import Button from '@/Components/ui/Button.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { StaffRecord } from '@/types/models';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    staff: StaffRecord[];
}>();

const page = usePage();
const sheetOpen = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    email: '',
    colour: DEFAULT_STAFF_COLOUR,
    is_bookable: true,
    is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.colour = DEFAULT_STAFF_COLOUR;
    form.is_bookable = true;
    form.is_active = true;
    sheetOpen.value = true;
};

const openEdit = (person: StaffRecord) => {
    editingId.value = person.id;
    form.name = person.name;
    form.email = person.email;
    form.colour = person.colour ?? DEFAULT_STAFF_COLOUR;
    form.is_bookable = person.is_bookable;
    form.is_active = person.is_active;
    sheetOpen.value = true;
};

const submit = () => {
    if (editingId.value) {
        form.patch(route('staff.update', editingId.value), {
            onSuccess: () => (sheetOpen.value = false),
        });
    } else {
        form.post(route('staff.store'), {
            onSuccess: () => (sheetOpen.value = false),
        });
    }
};

const deactivate = (person: StaffRecord) => {
    router.patch(route('staff.update', person.id), { is_active: false });
};

const columns: Column[] = [
    { key: 'name', label: 'Name', sortable: true },
    { key: 'email', label: 'Email', secondary: true },
    { key: 'bookable', label: 'Takes bookings', width: 'status' },
    { key: 'status', label: 'Status', width: 'status' },
];

/*
 * `colour` is per-user data, not a token — the one legitimate colour outside
 * the system, and the only place the admin app is not monochrome. It is a 6px
 * square beside the name and nothing else; it never fills a row or a block.
 */
const rows = computed(() => props.staff.map((person) => ({ ...person })));
</script>

<template>
    <AppLayout>
        <Head title="Staff" />
        <PageHeader title="Staff" description="People who work in the business.">
            <Button @click="openCreate">Add staff</Button>
        </PageHeader>

        <Table
            :columns="columns"
            :rows="rows"
            label="Staff"
            :row-label="(row) => `Actions for ${row.name}`"
            empty-title="Nobody here yet"
            empty-description="Add the people who take appointments and they become columns in the diary."
        >
            <template #cell:name="{ row }">
                <span class="flex items-center gap-2">
                    <span
                        class="inline-block h-2 w-2 shrink-0 rounded"
                        :style="{ backgroundColor: (row.colour as string) ?? DEFAULT_STAFF_COLOUR }"
                        aria-hidden="true"
                    />
                    {{ row.name }}
                </span>
            </template>

            <template #cell:bookable="{ row }">
                <Badge :tone="row.is_bookable ? 'confirmed' : 'neutral'">
                    {{ row.is_bookable ? 'Bookable' : 'Not bookable' }}
                </Badge>
            </template>

            <template #cell:status="{ row }">
                <Badge :tone="row.is_active ? 'confirmed' : 'neutral'">
                    {{ row.is_active ? 'Active' : 'Inactive' }}
                </Badge>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="openEdit(row as unknown as StaffRecord)">Edit</MenuItem>
                <MenuItem @click="router.get(route('availability.index'), { staff: Number(row.id) })">Hours</MenuItem>
                <MenuItem
                    v-if="row.is_active && row.id !== page.props.auth.user?.id"
                    danger
                    @click="deactivate(row as unknown as StaffRecord)"
                >
                    Deactivate
                </MenuItem>
            </template>

            <template #footer>
                <span class="numeral">{{ rows.length }}</span> on the team
            </template>

            <template #empty-action>
                <Button variant="ghost" @click="openCreate">Add someone</Button>
            </template>
        </Table>

        <SlideOver :show="sheetOpen" :title="editingId ? 'Edit staff' : 'Add staff'" @close="sheetOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <TextInput v-model="form.name" label="Name" :error="form.errors.name" required />
                <TextInput v-model="form.email" type="email" label="Email" :error="form.errors.email" required />
                <!--
                    The one colour the operator app lets anybody choose. It is
                    per-user *data*, not a token, and it is spent on a 6px square
                    beside a name — never on a diary block, which is what made
                    the old diary read as a spreadsheet.
                -->
                <StaffColourField v-model="form.colour" :error="form.errors.colour" />
                <Checkbox v-model="form.is_bookable" label="Takes bookings" hint="Appears as a column in the diary." />
                <Checkbox
                    v-if="editingId"
                    v-model="form.is_active"
                    label="Active"
                    hint="Inactive people keep their history but stop appearing anywhere new."
                />
            </form>
            <template #footer>
                <Button :loading="form.processing" @click="submit">Save</Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
