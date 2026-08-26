<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { penceToPoundsInput, poundsInputToPence } from '@/lib/money';
import type { ServiceRecord } from '@/types/models';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, reactive, ref } from 'vue';

const props = defineProps<{
    services: ServiceRecord[];
    staff: Array<{ id: number; name: string }>;
}>();

const sheetOpen = ref(false);
const editingId = ref<number | null>(null);
const deleteId = ref<number | null>(null);

const form = useForm({
    name: '',
    description: '',
    duration_minutes: 60,
    buffer_minutes: 0,
    suggested_interval_days: null as number | null,
    price: 0,
    deposit_amount: 0,
    is_active: true,
    staff_ids: [] as number[],
});

const money = reactive({
    price: '0.00',
    deposit: '0.00',
});

/*
 * "Not set" and "zero" are different answers here, and an empty number input
 * gives back an empty string rather than null — so the field holds a string and
 * `submit` turns a blank one back into null on the way out.
 */
const intervalField = ref<string>('');

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.is_active = true;
    form.staff_ids = [];
    money.price = '0.00';
    money.deposit = '0.00';
    intervalField.value = '';
    sheetOpen.value = true;
};

const openEdit = (service: ServiceRecord) => {
    editingId.value = service.id;
    form.name = service.name;
    form.description = service.description ?? '';
    form.duration_minutes = service.duration_minutes;
    form.buffer_minutes = service.buffer_minutes;
    intervalField.value = service.suggested_interval_days === null ? '' : String(service.suggested_interval_days);
    form.is_active = service.is_active;
    form.staff_ids = [...service.staff_ids];
    money.price = penceToPoundsInput(service.price.amount);
    money.deposit = penceToPoundsInput(service.deposit_amount.amount);
    sheetOpen.value = true;
};

const submit = () => {
    form.price = poundsInputToPence(money.price);
    form.deposit_amount = poundsInputToPence(money.deposit);
    form.suggested_interval_days = intervalField.value.trim() === '' ? null : Number(intervalField.value);

    if (editingId.value) {
        form.patch(route('services.update', editingId.value), {
            onSuccess: () => (sheetOpen.value = false),
        });
    } else {
        form.post(route('services.store'), {
            onSuccess: () => (sheetOpen.value = false),
        });
    }
};

const toggleActive = (service: ServiceRecord) => {
    router.patch(route('services.update', service.id), {
        is_active: !service.is_active,
    });
};

const confirmDelete = () => {
    if (!deleteId.value) {
        return;
    }

    router.delete(route('services.destroy', deleteId.value), {
        onSuccess: () => (deleteId.value = null),
    });
};

/**
 * Reordering, by menu rather than by drag.
 *
 * WCAG 2.2 requires a single-pointer alternative to any author-controlled drag,
 * and the old row was `draggable` with no other way to reorder at all — which
 * meant the order of the service list, and therefore the order a customer sees
 * on the booking page, was unreachable by keyboard. Move up and move down live
 * in the row's actions menu, where they are also easier to hit than a 34px drag
 * target.
 */
const move = (id: number, direction: -1 | 1) => {
    const ids = props.services.map((service) => service.id);
    const from = ids.indexOf(id);
    const to = from + direction;

    if (from === -1 || to < 0 || to >= ids.length) return;

    ids.splice(from, 1);
    ids.splice(to, 0, id);
    router.patch(route('services.reorder'), { ids });
};

const columns: Column[] = [
    { key: 'name', label: 'Name' },
    { key: 'duration_minutes', label: 'Duration', width: 'staff', align: 'right', numeric: true },
    { key: 'interval', label: 'Due again', width: 'staff', align: 'right', numeric: true, secondary: true },
    { key: 'price_amount', label: 'Price', width: 'amount', align: 'right', numeric: true },
    { key: 'deposit_amount_value', label: 'Deposit', width: 'amount', align: 'right', numeric: true, secondary: true },
    { key: 'state', label: 'Status', width: 'status' },
];

/*
 * No `sortable` on this table, and that is the point: the order **is** the
 * data. It is the order a customer sees on the booking page, and a column
 * header that silently reorders it would be a control that looks like a view
 * and behaves like an edit.
 */
const rows = computed(() =>
    props.services.map((service) => ({
        ...service,
        price_amount: service.price.amount,
        deposit_amount_value: service.deposit_amount.amount,
        interval: service.suggested_interval_days ?? 0,
        state: service.is_active ? 'On the booking page' : 'Hidden',
    })),
);
</script>

<template>
    <AppLayout>
        <Head title="Services" />
        <PageHeader title="Services" description="What you offer, how long it takes, and what it costs.">
            <Button @click="openCreate">Add service</Button>
        </PageHeader>

        <Table
            :columns="columns"
            :rows="rows"
            label="Services, in the order customers see them"
            :row-label="(row) => `Actions for ${row.name}`"
            empty-title="No services yet"
            empty-description="Nothing can be booked until there is something to book. A name, a length and a price is enough to start."
        >
            <template #cell:name="{ row }">
                {{ row.name }}
                <span v-if="row.buffer_minutes" class="text-ink-2">
                    · <span class="numeral">{{ row.buffer_minutes }}</span> min buffer
                </span>
            </template>

            <template #cell:duration_minutes="{ row }">{{ row.duration_minutes }} min</template>

            <!-- What the suggester falls back to for a customer with no rhythm
                 of their own. See `AppointmentSuggester`. -->
            <template #cell:interval="{ row }">
                <span v-if="row.suggested_interval_days">{{ row.suggested_interval_days }} d</span>
                <span v-else class="text-ink-2">—</span>
            </template>

            <template #cell:price_amount="{ row }">{{ (row.price as ServiceRecord['price']).formatted }}</template>
            <template #cell:deposit_amount_value="{ row }">
                <span :class="row.deposit_amount_value ? '' : 'text-ink-2'">
                    {{ (row.deposit_amount as ServiceRecord['deposit_amount']).formatted }}
                </span>
            </template>

            <template #cell:state="{ row }">
                <Badge :tone="row.is_active ? 'confirmed' : 'neutral'">{{ row.state }}</Badge>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="openEdit(row as unknown as ServiceRecord)">Edit</MenuItem>
                <MenuItem :disabled="rows[0]?.id === row.id" @click="move(Number(row.id), -1)">Move up</MenuItem>
                <MenuItem :disabled="rows[rows.length - 1]?.id === row.id" @click="move(Number(row.id), 1)">
                    Move down
                </MenuItem>
                <MenuItem @click="toggleActive(row as unknown as ServiceRecord)">
                    {{ row.is_active ? 'Hide from the booking page' : 'Show on the booking page' }}
                </MenuItem>
                <MenuItem danger @click="deleteId = Number(row.id)">Delete</MenuItem>
            </template>

            <template #footer>
                <span class="numeral">{{ rows.length }}</span> service{{ rows.length === 1 ? '' : 's' }}, in the order
                customers see them
            </template>

            <template #empty-action>
                <Button variant="ghost" @click="openCreate">Add the first one</Button>
            </template>
        </Table>

        <SlideOver :show="sheetOpen" :title="editingId ? 'Edit service' : 'Add service'" @close="sheetOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <TextInput v-model="form.name" label="Name" :error="form.errors.name" required />
                <Textarea
                    v-model="form.description"
                    label="Description"
                    :rows="3"
                    hint="Shown to customers on the booking page."
                    :error="form.errors.description"
                />

                <div class="grid grid-cols-2 gap-3">
                    <TextInput
                        v-model.number="form.duration_minutes"
                        type="number"
                        label="Duration"
                        suffix="min"
                        :error="form.errors.duration_minutes"
                        required
                    />
                    <TextInput
                        v-model.number="form.buffer_minutes"
                        type="number"
                        label="Buffer"
                        suffix="min"
                        hint="Clean-up time held after each one."
                        :error="form.errors.buffer_minutes"
                    />
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <TextInput v-model="money.price" label="Price" prefix="£" mono :error="form.errors.price" required />
                    <TextInput
                        v-model="money.deposit"
                        label="Deposit"
                        prefix="£"
                        mono
                        hint="Zero means pay on the day."
                        :error="form.errors.deposit_amount"
                    />
                </div>

                <!--
                    How long before this is due again. It is what
                    `AppointmentSuggester` falls back to for a customer with no
                    rhythm of their own — a nail clip comes round every three
                    weeks and a double-coat groom every ten, and one number for
                    both is wrong for both.
                -->
                <TextInput
                    v-model="intervalField"
                    type="number"
                    label="Due again after"
                    suffix="days"
                    hint="Left blank, the booking page proposes six weeks for a new customer."
                    :error="form.errors.suggested_interval_days"
                />

                <Checkbox
                    v-model="form.is_active"
                    label="On the booking page"
                    hint="Turning this off hides it from customers. Existing bookings are untouched."
                />

                <fieldset class="space-y-2">
                    <legend class="caption">Who can do this</legend>
                    <Checkbox
                        v-for="person in staff"
                        :key="person.id"
                        :model-value="form.staff_ids.includes(person.id)"
                        :label="person.name"
                        @update:model-value="
                            form.staff_ids = $event
                                ? [...form.staff_ids, person.id]
                                : form.staff_ids.filter((id) => id !== person.id)
                        "
                    />
                    <!-- The rule from DECISIONS.md, stated where it bites. -->
                    <p v-if="form.staff_ids.length === 0" class="text-12 text-ink-2">
                        With nobody selected this service has no bookable slots at all.
                    </p>
                </fieldset>
            </form>
            <template #footer>
                <Button :loading="form.processing" @click="submit">Save changes</Button>
            </template>
        </SlideOver>

        <ConfirmDialog
            :show="deleteId !== null"
            title="Delete this service?"
            @close="deleteId = null"
            @confirm="confirmDelete"
        >
            This removes it from your list. Existing bookings stay as they are.
        </ConfirmDialog>
    </AppLayout>
</template>
