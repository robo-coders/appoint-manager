<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    entries: Array<{
        id: number;
        customer_id: number | null;
        customer_name: string | null;
        phone: string | null;
        subject_name: string | null;
        service_name: string | null;
        preferred_days: number[];
        preferred_times: string | null;
        waiting_since: string | null;
        is_active: boolean;
    }>;
    services: Array<{ id: number; name: string }>;
    freed: {
        booking_id: number;
        time: string;
        date: string;
        customer: string | null;
        staff: string | null;
        minutes: number;
        waiting: number;
        offers_sent: number;
    } | null;
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
    { key: 'rank', label: '#', width: 'time', numeric: true, narrow: 'lead' },
    { key: 'customer_name', label: 'Customer', sortable: true, narrow: 'title' },
    { key: 'service_name', label: 'Wants', sortable: true, narrow: 'line' },
    { key: 'preference', label: 'Flexible on', secondary: true, narrow: 'line' },
    {
        key: 'waited',
        label: 'Waited',
        width: 'staff',
        align: 'right',
        numeric: true,
        sortable: true,
        narrow: 'meta',
    },
    { key: 'state', label: 'Status', width: 'status', narrow: 'meta' },
];

const DAYS = ['', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun'];

const preference = (entry: (typeof props.entries)[number]) => {
    const days = (entry.preferred_days ?? []).map((day) => DAYS[day]).filter(Boolean);
    const time = entry.preferred_times && entry.preferred_times !== 'any' ? entry.preferred_times : null;

    if (days.length === 0 && time === null) return 'Any time';

    return [days.length ? days.join(', ') : 'Any day', time ?? 'any time'].join(' · ');
};

const hoursWaiting = (since: string | null) => {
    if (!since) return 0;

    return Math.max(0, Math.floor((Date.now() - new Date(since).getTime()) / 3_600_000));
};

const waitLabel = (hours: number) => `${Math.floor(hours / 24)} d ${String(hours % 24).padStart(2, '0')} h`;

const rows = computed(() => {
    let place = 0;

    return props.entries.map((entry) => {
        const rank = entry.is_active ? ++place : null;

        return {
            ...entry,
            rank,
            preference: preference(entry),
            waited: hoursWaiting(entry.waiting_since),
            state: entry.is_active ? (rank === 1 ? 'Next up' : 'Waiting') : 'Done',
        };
    });
});

const subtitle = (row: { subject_name: string | null; service_name: string | null }) =>
    [row.subject_name, row.service_name?.split(' — ')[0]].filter(Boolean).join(' · ');

const offerLabel = computed(() => {
    const freed = props.freed;
    if (!freed) return '';
    if (freed.offers_sent > 0) return `${freed.offers_sent} offer${freed.offers_sent === 1 ? '' : 's'} out`;

    return freed.waiting > 0 ? `Offer to ${freed.waiting} waiting` : 'Fill this slot';
});

const freedLine = computed(() => {
    const freed = props.freed;
    if (!freed) return '';

    return `Freed — ${freed.customer ?? 'Somebody'} cancelled, ${freed.minutes} min open with ${freed.staff ?? 'nobody'} on ${freed.date}.`;
});

const sendOffer = () => {
    if (props.freed) router.post(route('waitlist.offer', props.freed.booking_id));
};

const waiting = computed(() => rows.value.filter((row) => row.is_active));

const longest = computed(() =>
    waiting.value.length === 0 ? null : waitLabel(Math.max(...waiting.value.map((row) => row.waited))),
);
</script>

<template>
    <AppLayout>
        <Head title="Waitlist" />
        <PageHeader title="Waitlist" description="A live queue, ordered by how long they have waited.">
            <Button @click="sheetOpen = true">Add to waitlist</Button>
        </PageHeader>

        <div
            v-if="freed"
            class="mb-6 flex flex-wrap items-center gap-x-3 gap-y-2 rounded border border-accent-rule bg-accent-tint px-3.5 py-3"
        >
            <span aria-hidden="true" class="size-1.5 shrink-0 animate-pulse rounded bg-accent"></span>
            <span class="numeral shrink-0 text-12 text-accent-strong">{{ freed.time }}</span>
            <span class="text-13 text-ink">{{ freedLine }}</span>
            <Button variant="accent-solid" class="ml-auto shrink-0" @click="sendOffer">{{ offerLabel }}</Button>
        </div>

        <Table
            chrome="bare"
            :columns="columns"
            :rows="rows"
            :initial-sort="{ key: 'waited', direction: 'desc' }"
            label="Waitlist"
            :row-label="(row) => `Actions for ${row.customer_name}`"
            empty-title="Nobody is waiting"
            empty-description="When somebody cancels, this is the list the offer goes out to. An empty waitlist is a cancellation that costs you the whole slot."
        >
            <template #cell:customer_name="{ row }">
                <span class="block font-medium text-ink">{{ row.customer_name }}</span>
                <span v-if="subtitle(row)" class="mt-0.5 block truncate text-12 text-ink-2">{{ subtitle(row) }}</span>
            </template>

            <template #cell:rank="{ row }">
                <span v-if="row.rank" :class="row.rank === 1 ? 'font-medium text-accent-strong' : 'text-ink-2'">
                    #{{ row.rank }}
                </span>
            </template>

            <template #narrow:rank="{ row }">
                <span
                    v-if="row.rank"
                    class="numeral text-17"
                    :class="row.rank === 1 ? 'font-medium text-accent-strong' : 'text-ink-2'"
                >
                    {{ row.rank }}
                </span>
            </template>

            <template #cell:waited="{ row }">
                {{ waitLabel(Number(row.waited)) }}
            </template>

            <template #cell:state="{ row }">
                <span class="flex items-center gap-2">
                    <span
                        aria-hidden="true"
                        class="size-1.5 shrink-0 rounded"
                        :class="row.rank === 1 ? 'animate-pulse bg-accent' : 'bg-ink-3'"
                    ></span>
                    <span
                        class="text-12 font-medium"
                        :class="row.rank === 1 ? 'text-accent-strong' : 'text-ink-2'"
                    >{{ row.state }}</span>
                </span>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="router.get(route('diary.index'))">Find them a slot</MenuItem>
                <MenuItem v-if="row.customer_id" @click="router.get(route('customers.show', Number(row.customer_id)))">
                    Open the customer
                </MenuItem>
            </template>

            <template #footer>
                <span class="numeral">{{ waiting.length }}</span> waiting of
                <span class="numeral">{{ rows.length }}</span>
                <template v-if="longest"> · longest <span class="numeral">{{ longest }}</span></template>
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
