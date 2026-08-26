<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Who is waiting, for what, and for how long.
 *
 * The add form was five hand-rolled inputs sitting permanently above the table,
 * taking up the top of the screen for something a salon does a few times a
 * week. It is a `SlideOver` now, behind one button, on the shared components
 * with real error binding.
 */
const props = defineProps<{
    entries: Array<{
        id: number;
        customer_id: number | null;
        customer_name: string | null;
        phone: string | null;
        service_name: string | null;
        preferred_days: number[];
        preferred_times: string | null;
        waiting_since: string | null;
        is_active: boolean;
    }>;
    services: Array<{ id: number; name: string }>;
}>();

const sheetOpen = ref(false);

const form = useForm({
    name: '',
    email: '',
    phone: '',
    service_id: '' as string | number,
    preferred_times: 'any',
});

const submit = () =>
    form.post(route('waitlist.store'), {
        onSuccess: () => {
            form.reset();
            sheetOpen.value = false;
        },
    });

const columns: Column[] = [
    { key: 'customer_name', label: 'Customer', sortable: true },
    { key: 'service_name', label: 'Service', sortable: true },
    { key: 'preference', label: 'Prefers', secondary: true },
    { key: 'waited', label: 'Waiting', width: 'staff', align: 'right', numeric: true, sortable: true },
    { key: 'state', label: 'Status', width: 'status' },
];

const DAYS = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const preference = (entry: (typeof props.entries)[number]) => {
    const days = (entry.preferred_days ?? []).map((day) => DAYS[day]).filter(Boolean);
    const time = entry.preferred_times && entry.preferred_times !== 'any' ? entry.preferred_times : null;

    if (days.length === 0 && time === null) return 'Any time';

    return [days.length ? days.join(', ') : 'Any day', time ?? 'any time'].join(' · ');
};

/** Days waited. A number, so it sorts — the column shows it with its unit. */
const daysWaiting = (since: string | null) => {
    if (!since) return 0;

    return Math.max(0, Math.floor((Date.now() - new Date(since).getTime()) / 86_400_000));
};

const rows = computed(() =>
    props.entries.map((entry) => ({
        ...entry,
        preference: preference(entry),
        waited: daysWaiting(entry.waiting_since),
        state: entry.is_active ? 'Waiting' : 'Done',
    })),
);
</script>

<template>
    <AppLayout>
        <Head title="Waitlist" />
        <PageHeader title="Waitlist" description="Who is waiting, for what, and for how long.">
            <Button @click="sheetOpen = true">Add to waitlist</Button>
        </PageHeader>

        <Table
            :columns="columns"
            :rows="rows"
            label="Waitlist"
            :row-label="(row) => `Actions for ${row.customer_name}`"
            empty-title="Nobody is waiting"
            empty-description="When somebody cancels, this is the list the offer goes out to. An empty waitlist is a cancellation that costs you the whole slot."
        >
            <template #cell:customer_name="{ row }">
                {{ row.customer_name }}
                <span v-if="row.phone" class="numeral text-ink-2">· {{ row.phone }}</span>
            </template>

            <template #cell:waited="{ row }">
                <span class="numeral">{{ row.waited }}</span> d
            </template>

            <template #cell:state="{ row }">
                <Badge :tone="row.is_active ? 'accent' : 'neutral'">{{ row.state }}</Badge>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="router.get(route('diary.index'))">Find them a slot</MenuItem>
                <MenuItem v-if="row.customer_id" @click="router.get(route('customers.show', Number(row.customer_id)))">
                    Open the customer
                </MenuItem>
            </template>

            <template #footer>
                <span class="numeral">{{ rows.filter((r) => r.is_active).length }}</span> still waiting of
                <span class="numeral">{{ rows.length }}</span>
            </template>

            <template #empty-action>
                <Button variant="ghost" @click="sheetOpen = true">Add somebody</Button>
            </template>
        </Table>

        <SlideOver :show="sheetOpen" title="Add to waitlist" @close="sheetOpen = false">
            <form class="space-y-3" @submit.prevent="submit">
                <TextInput v-model="form.name" label="Name" :error="form.errors.name" required />
                <TextInput v-model="form.email" type="email" label="Email" :error="form.errors.email" required />
                <TextInput
                    v-model="form.phone"
                    type="tel"
                    label="Mobile"
                    hint="Where the offer text goes when a slot opens."
                    :error="form.errors.phone"
                    required
                />
                <Select
                    v-model="form.service_id"
                    label="Service"
                    :error="form.errors.service_id"
                    :options="[
                        { value: '', label: 'Choose a service' },
                        ...services.map((service) => ({ value: service.id, label: service.name })),
                    ]"
                />
                <Select
                    v-model="form.preferred_times"
                    label="Prefers"
                    :error="form.errors.preferred_times"
                    :options="[
                        { value: 'any', label: 'Any time' },
                        { value: 'morning', label: 'Mornings' },
                        { value: 'afternoon', label: 'Afternoons' },
                    ]"
                />
            </form>
            <template #footer>
                <Button :loading="form.processing" @click="submit">Add to waitlist</Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
