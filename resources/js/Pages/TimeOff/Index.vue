<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Time off, on the shared table.
 *
 * The form was eight hand-rolled controls in a permanent panel above the list —
 * a two-column grid taking half the screen for something a salon does a handful
 * of times a year. It is behind one button now, on the component library, with
 * every error bound to its own field.
 */
const props = defineProps<{
    entries: Array<{
        id: number;
        user_id: number;
        user_name: string | null;
        starts_at_local: string | null;
        ends_at_local: string | null;
        reason: string | null;
        is_all_day: boolean;
    }>;
    staff: Array<{ id: number; name: string }>;
    timezone: string;
}>();

const sheetOpen = ref(false);

const form = useForm({
    user_id: props.staff[0]?.id ?? 0,
    starts_on: '',
    ends_on: '',
    start_time: '09:00',
    end_time: '17:00',
    is_all_day: false,
    reason: '',
});

const submit = () =>
    form.post(route('time-off.store'), {
        onSuccess: () => {
            form.reset('reason', 'starts_on', 'ends_on');
            sheetOpen.value = false;
        },
    });

const columns: Column[] = [
    { key: 'user_name', label: 'Staff', width: 'staff', sortable: true },
    { key: 'from', label: 'From', width: 'when', sortable: true },
    { key: 'to', label: 'To', width: 'when' },
    { key: 'reason', label: 'Reason', secondary: true },
];

const rows = computed(() =>
    props.entries.map((entry) => ({
        ...entry,
        from: entry.starts_at_local ?? '',
        to: entry.ends_at_local ?? '',
    })),
);
</script>

<template>
    <AppLayout>
        <Head title="Time off" />
        <PageHeader title="Time off" :description="`Stored in UTC, shown in ${timezone}.`">
            <Button @click="sheetOpen = true">Add time off</Button>
        </PageHeader>

        <Table
            :columns="columns"
            :rows="rows"
            label="Time off"
            :row-label="(row) => `Actions for ${row.user_name}, ${row.from}`"
            empty-title="No time off booked"
            empty-description="Holidays and half days added here stop the booking page offering those hours to anybody."
        >
            <template #cell:from="{ row }">
                <span class="numeral">{{ row.is_all_day ? String(row.from).slice(0, 10) : row.from }}</span>
            </template>
            <template #cell:to="{ row }">
                <span class="numeral">{{ row.is_all_day ? String(row.to).slice(0, 10) : row.to }}</span>
                <span v-if="row.is_all_day" class="text-ink-2"> · all day</span>
            </template>
            <template #cell:reason="{ row }">
                {{ row.reason || '—' }}
            </template>

            <template #actions="{ row }">
                <MenuItem @click="router.get(route('diary.index'), { date: String(row.from).slice(0, 10) })">
                    Show in the diary
                </MenuItem>
                <MenuItem danger @click="router.delete(route('time-off.destroy', Number(row.id)))">Remove</MenuItem>
            </template>

            <template #footer>
                <span class="numeral">{{ rows.length }}</span> booked
            </template>

            <template #empty-action>
                <Button variant="ghost" @click="sheetOpen = true">Add some</Button>
            </template>
        </Table>

        <SlideOver :show="sheetOpen" title="Add time off" @close="sheetOpen = false">
            <form class="space-y-3" @submit.prevent="submit">
                <Select
                    v-model.number="form.user_id"
                    label="Staff"
                    :error="form.errors.user_id"
                    :options="staff.map((person) => ({ value: person.id, label: person.name }))"
                />
                <TextInput v-model="form.starts_on" type="date" label="Starts on" :error="form.errors.starts_on" required />
                <TextInput v-model="form.ends_on" type="date" label="Ends on" :error="form.errors.ends_on" required />

                <Checkbox v-model="form.is_all_day" label="All day" hint="Blocks the whole of every day in the range." />

                <!-- Times only exist when it is not all day. Showing them
                     disabled would be two controls arguing about which one
                     matters. -->
                <template v-if="!form.is_all_day">
                    <TextInput v-model="form.start_time" type="time" label="From" :error="form.errors.start_time" />
                    <TextInput v-model="form.end_time" type="time" label="Until" :error="form.errors.end_time" />
                </template>

                <TextInput v-model="form.reason" label="Reason" hint="Only you see this." :error="form.errors.reason" />
            </form>
            <template #footer>
                <Button :loading="form.processing" @click="submit">Add time off</Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
