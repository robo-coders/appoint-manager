<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import DateTime from '@/Components/ui/DateTime.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { toast } from '@/lib/toast';
import type { Money } from '@/types/models';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

interface DiaryBooking {
    id: number;
    staff_id: number;
    staff_name: string;
    staff_colour: string | null;
    service_name: string;
    customer_name: string;
    subject_name: string | null;
    starts_at_local: string;
    ends_at_local: string;
    status: string;
    source: string;
}

const props = defineProps<{
    view: 'day' | 'week';
    date: string;
    range_start: string;
    timezone: string;
    staff: Array<{ id: number; name: string; colour: string | null; is_bookable: boolean }>;
    services: Array<{ id: number; name: string; duration_minutes: number; price: Money }>;
    bookings: DiaryBooking[];
}>();

const page = usePage();
const startHour = 8;
const hours = 12;
const pixelsPerHour = 48;
const selected = ref<DiaryBooking | null>(null);
const createOpen = ref(false);
const optimistic = ref<DiaryBooking[]>([]);
const formError = ref('');

const form = useForm({
    service_id: props.services[0]?.id ?? 0,
    staff_id: props.staff.find((person) => person.is_bookable)?.id ?? props.staff[0]?.id ?? 0,
    starts_at: `${props.date}T09:00`,
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    subject_name: '',
});

const shown = computed(() => [...props.bookings, ...optimistic.value]);

const columns = computed(() => {
    if (props.view === 'week') {
        return Array.from({ length: 7 }, (_, index) => {
            const date = addDays(props.range_start, index);
            return { key: date, label: date, staffId: null as number | null };
        });
    }

    return props.staff.map((person) => ({
        key: String(person.id),
        label: person.name,
        staffId: person.id,
    }));
});

const bookingsFor = (columnKey: string, staffId: number | null) =>
    shown.value.filter((booking) => {
        const localDate = booking.starts_at_local.slice(0, 10);
        if (props.view === 'week') {
            return localDate === columnKey;
        }

        return booking.staff_id === staffId;
    });

const topFor = (booking: DiaryBooking) => {
    const [hour, minute] = booking.starts_at_local.slice(11).split(':').map(Number);
    return ((hour - startHour) * 60 + minute) * (pixelsPerHour / 60);
};

const heightFor = (booking: DiaryBooking) => {
    const [sh, sm] = booking.starts_at_local.slice(11).split(':').map(Number);
    const [eh, em] = booking.ends_at_local.slice(11).split(':').map(Number);
    return Math.max((eh * 60 + em - (sh * 60 + sm)) * (pixelsPerHour / 60), 24);
};

const addDays = (value: string, amount: number) => {
    const [year, month, day] = value.split('-').map(Number);
    const next = new Date(year, month - 1, day + amount);

    return [
        next.getFullYear(),
        String(next.getMonth() + 1).padStart(2, '0'),
        String(next.getDate()).padStart(2, '0'),
    ].join('-');
};

const go = (date: string, view = props.view) => {
    router.get(route('diary.index'), { date, view }, { preserveState: true, preserveScroll: true });
};

const openCreate = (staffId: number | null, event: MouseEvent) => {
    const target = event.currentTarget as HTMLElement;
    const rect = target.getBoundingClientRect();
    const minutes = Math.round((((event.clientY - rect.top) / (hours * pixelsPerHour)) * hours * 60) / 15) * 15;
    const hour = startHour + Math.floor(minutes / 60);
    const minute = minutes % 60;
    const day = props.view === 'week' ? target.dataset.day ?? props.date : props.date;

    form.staff_id = staffId ?? form.staff_id;
    form.starts_at = `${day}T${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`;
    formError.value = '';
    createOpen.value = true;
};

const submit = () => {
    const service = props.services.find((item) => item.id === form.service_id);
    const person = props.staff.find((item) => item.id === form.staff_id);
    const [day, time] = form.starts_at.split('T');
    const [hour, minute] = (time ?? '09:00').split(':').map(Number);
    const end = hour * 60 + minute + (service?.duration_minutes ?? 60);
    const temp: DiaryBooking = {
        id: -Date.now(),
        staff_id: form.staff_id,
        staff_name: person?.name ?? '',
        staff_colour: person?.colour ?? null,
        service_name: service?.name ?? '',
        customer_name: form.customer_name || 'New booking',
        subject_name: form.subject_name || null,
        starts_at_local: `${day} ${String(hour).padStart(2, '0')}:${String(minute).padStart(2, '0')}`,
        ends_at_local: `${day} ${String(Math.floor(end / 60)).padStart(2, '0')}:${String(end % 60).padStart(2, '0')}`,
        status: 'confirmed',
        source: 'manual',
    };

    optimistic.value.push(temp);
    createOpen.value = false;
    toast('Booking saved.');

    form.post(route('bookings.store'), {
        preserveScroll: true,
        onError: () => {
            optimistic.value = optimistic.value.filter((booking) => booking.id !== temp.id);
            formError.value = form.errors.starts_at || 'That time isn’t free. Pick another slot.';
            createOpen.value = true;
        },
        onSuccess: () => {
            optimistic.value = optimistic.value.filter((booking) => booking.id !== temp.id);
            form.reset();
            form.service_id = props.services[0]?.id ?? 0;
        },
    });
};

watch(
    () => page.url,
    (url) => {
        if (url.includes('new=1')) {
            createOpen.value = true;
        }
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout>
        <Head title="Diary" />
        <PageHeader title="Diary" :description="`Times in ${timezone}.`">
            <Button @click="createOpen = true">New booking</Button>
        </PageHeader>

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <Button variant="secondary" @click="go(addDays(date, view === 'week' ? -7 : -1))">Earlier</Button>
            <Button variant="secondary" @click="go(page.props.today ?? date)">Today</Button>
            <Button variant="secondary" @click="go(addDays(date, view === 'week' ? 7 : 1))">Later</Button>
            <Button :variant="view === 'day' ? 'primary' : 'secondary'" @click="go(date, 'day')">Day</Button>
            <Button :variant="view === 'week' ? 'primary' : 'secondary'" @click="go(date, 'week')">Week</Button>
            <p class="text-13 text-ink-2">{{ view === 'week' ? range_start : date }}</p>
        </div>

        <EmptyState
            v-if="staff.length === 0"
            title="Add someone bookable and they will show up here as a column."
            action-label="Add staff"
            @action="router.visit(route('staff.index'))"
        />

        <div v-else class="overflow-x-auto rounded border border-rule bg-white">
            <div
                class="min-w-[380px]"
                :style="{ display: 'grid', gridTemplateColumns: `3rem repeat(${columns.length}, minmax(7rem, 1fr))` }"
            >
                <div class="border-b border-rule" />
                <div
                    v-for="column in columns"
                    :key="column.key"
                    class="border-b border-l border-rule px-2 py-2 text-13"
                >
                    {{ column.label }}
                </div>
                <div class="relative" :style="{ height: `${hours * pixelsPerHour}px` }">
                    <div
                        v-for="hour in hours"
                        :key="hour"
                        class="absolute left-0 right-0 border-t border-rule px-1 text-12 text-ink-2"
                        :style="{ top: `${(hour - 1) * pixelsPerHour}px`, height: `${pixelsPerHour}px` }"
                    >
                        {{ String(startHour + hour - 1).padStart(2, '0') }}:00
                    </div>
                </div>
                <div
                    v-for="column in columns"
                    :key="`grid-${column.key}`"
                    class="relative border-l border-rule"
                    :data-day="view === 'week' ? column.key : date"
                    :style="{ height: `${hours * pixelsPerHour}px` }"
                    @click="openCreate(column.staffId, $event)"
                >
                    <div
                        v-for="hour in hours"
                        :key="hour"
                        class="absolute inset-x-0 border-t border-rule"
                        :style="{ top: `${(hour - 1) * pixelsPerHour}px`, height: `${pixelsPerHour}px` }"
                    />
                    <button
                        v-for="booking in bookingsFor(column.key, column.staffId)"
                        :key="booking.id"
                        type="button"
                        class="absolute inset-x-1 overflow-hidden rounded px-1 py-1 text-left text-12 text-white"
                        :style="{
                            top: `${topFor(booking)}px`,
                            height: `${heightFor(booking)}px`,
                            background: booking.staff_colour || 'var(--color-accent)',
                        }"
                        @click.stop="selected = booking"
                    >
                        <span class="block font-medium">{{ booking.customer_name }}</span>
                        <span class="block">{{ booking.service_name }}</span>
                    </button>
                </div>
            </div>
        </div>

        <SlideOver :show="selected !== null" :title="selected?.customer_name ?? 'Booking'" @close="selected = null">
            <div v-if="selected" class="space-y-2 text-14">
                <p>{{ selected.service_name }}</p>
                <p class="text-ink-2">
                    <DateTime :value="selected.starts_at_local" />
                    –
                    {{ selected.ends_at_local.slice(11) }}
                </p>
                <p class="text-ink-2">{{ selected.staff_name }}</p>
                <p v-if="selected.subject_name">{{ selected.subject_name }}</p>
                <Link :href="route('bookings.show', selected.id)" class="inline-block text-14 underline decoration-rule underline-offset-4">
                    Open booking
                </Link>
            </div>
        </SlideOver>

        <SlideOver :show="createOpen" title="New booking" @close="createOpen = false">
            <form class="space-y-3" @submit.prevent="submit">
                <p v-if="formError" class="text-13 text-danger" role="alert">{{ formError }}</p>
                <Select v-model="form.service_id" label="Service">
                    <option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
                </Select>
                <Select v-model="form.staff_id" label="Staff">
                    <option v-for="person in staff" :key="person.id" :value="person.id">{{ person.name }}</option>
                </Select>
                <TextInput v-model="form.starts_at" type="datetime-local" label="Starts" :error="form.errors.starts_at" />
                <TextInput v-model="form.customer_name" label="Client name" :error="form.errors.customer_name" />
                <TextInput v-model="form.customer_email" type="email" label="Email" :error="form.errors.customer_email" />
                <TextInput v-model="form.customer_phone" label="Phone" :error="form.errors.customer_phone" />
                <TextInput v-model="form.subject_name" :label="page.props.vertical.subject_singular + ' name'" />
                <Button type="submit" :disabled="form.processing">Save booking</Button>
            </form>
        </SlideOver>
    </AppLayout>
</template>
