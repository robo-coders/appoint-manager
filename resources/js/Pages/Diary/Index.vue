<script setup lang="ts">
import DayAgenda from '@/Components/Diary/DayAgenda.vue';
import DayGrid from '@/Components/Diary/DayGrid.vue';
import { annotate, gapsIn, minutesOf, timeOf, type DiaryBooking, type Gap } from '@/Components/Diary/diary';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PendingRequests, { type PendingRequest } from '@/Components/PendingRequests.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { sentenceCase } from '@/lib/copy';
import { toast } from '@/lib/toast';
import type { Money } from '@/types/models';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * The diary.
 *
 * There is no approved mockup for this screen and the three that were built
 * were rejected, so nothing here is invented: it is `dashboard.html`'s timeline
 * language extended to a full day with staff as columns. The row component the
 * dashboard uses — `ui/TimelineRow` — is the same one the 375px agenda is built
 * from, and the grid's blocks (`ui/TimeBlock`) restate the same three rules:
 * muted past with no detail, a 2px ink left border on the current appointment,
 * and the freed slot as the only coloured thing on screen.
 *
 * The two questions the brief said must be answered here:
 *
 * **Gap-finding.** Open time is drawn as space, not counted as a statistic —
 * `ui/GapButton` occupies the minutes it represents and books into itself. See
 * that component for the argument.
 *
 * **375px.** Staff columns do not fit, so below `md` the grid is replaced by a
 * single-column agenda built from the dashboard's own row, plus a staff filter.
 * `DayAgenda` documents the two options that were rejected and why.
 */

const props = defineProps<{
    view: 'day' | 'week';
    date: string;
    range_start: string;
    timezone: string;
    staff: Array<{ id: number; name: string; colour: string | null; is_bookable: boolean }>;
    services: Array<{
        id: number;
        name: string;
        duration_minutes: number;
        price: Money;
        suggested_interval_days: number | null;
    }>;
    bookings: DiaryBooking[];
    /** Keyed by staff id. Day view only. */
    working: Record<number, Array<{ start: string; end: string }>>;
    now: string;
    is_today: boolean;
    /** True when nobody has hours on this day. The grid is not drawn. */
    closed: boolean;
    next_open: string | null;
    pending_requests: PendingRequest[];
}>();

const page = usePage();
const selected = ref<DiaryBooking | null>(null);
const createOpen = ref(false);
const formError = ref('');
const optimistic = ref<DiaryBooking[]>([]);
const filterStaffId = ref<number | null>(null);
const narrow = ref(false);

/*
 * The current-time hairline is live. A diary that draws "now" once, at page
 * load, is a diary that is wrong for the rest of the shift — and this is a
 * screen that stays open all day.
 */
const nowLocal = ref(props.now);
let clock: ReturnType<typeof setInterval> | undefined;

const form = useForm({
    service_id: props.services[0]?.id ?? 0,
    staff_id: props.staff.find((person) => person.is_bookable)?.id ?? props.staff[0]?.id ?? 0,
    starts_at: `${props.date}T09:00`,
    customer_name: '',
    customer_email: '',
    customer_phone: '',
    subject_name: '',
    rebook_interval_days: (props.services[0]?.suggested_interval_days ?? '') as string | number,
    correlation_id: '',
});

const intervalLabel = (days: number) => {
    if (days % 7 === 0) {
        const weeks = days / 7;

        return weeks === 1 ? '1 week' : `${weeks} weeks`;
    }

    return `${days} days`;
};

const chosenService = computed(() => props.services.find((item) => item.id === Number(form.service_id)));

const intervalFromService = computed(() => {
    const days = chosenService.value?.suggested_interval_days;

    return days != null && Number(form.rebook_interval_days) === days;
});

const intervalOptions = computed(() => {
    const days = chosenService.value?.suggested_interval_days;
    const values = [21, 28, 35, 42, 49, 56];

    if (days != null && !values.includes(days)) {
        values.unshift(days);
    }

    return [
        { value: '', label: 'The usual' },
        ...values.map((value) => ({ value, label: intervalLabel(value) })),
    ];
});

const shown = computed(() => {
    const serverIds = new Set(props.bookings.map((booking) => booking.id));
    const extras = optimistic.value.filter((booking) => !serverIds.has(booking.id));

    return annotate([...props.bookings, ...extras], props.is_today ? nowLocal.value : null);
});

const gaps = computed<Gap[]>(() => {
    if (props.view !== 'day') return [];

    return props.staff.flatMap((member) => gapsIn(member.id, props.working[member.id] ?? [], shown.value));
});

/**
 * The drawn day: the earliest start and the latest end anybody has, whether
 * that is a working window or an appointment that runs past one.
 *
 * Not a fixed 08:00–20:00. A salon that opens at 09:00 does not want an hour of
 * empty grid above its first appointment, and a groomer who is still going at
 * 19:30 must not have that appointment drawn off the bottom of the screen.
 */
const bounds = computed(() => {
    const edges: number[] = [];

    for (const windows of Object.values(props.working)) {
        for (const window of windows) {
            edges.push(minutesOf(window.start), minutesOf(window.end));
        }
    }

    for (const booking of shown.value) {
        edges.push(minutesOf(booking.starts_at_local.slice(11)), minutesOf(booking.ends_at_local.slice(11)));
    }

    if (edges.length === 0) return { start: '09:00', end: '17:00' };

    // Rounded out to the hour, so the gridlines land where the labels are.
    const start = Math.floor(Math.min(...edges) / 60) * 60;
    const end = Math.ceil(Math.max(...edges) / 60) * 60;

    return { start: timeOf(start), end: timeOf(Math.max(end, start + 120)) };
});

/** Everything on today that is a gap somebody can still be offered. */
const freed = computed(() => shown.value.filter((booking) => booking.is_freed));

/**
 * The one number, scoped to what is actually on screen.
 *
 * At 375px with `Everyone` selected the agenda draws no gaps at all — see
 * `DayAgenda` — so an aggregate over gaps nobody can see is a claim the screen
 * cannot back up. It follows the filter.
 */
const visibleGaps = computed(() =>
    filterStaffId.value === null ? gaps.value : gaps.value.filter((gap) => gap.staff_id === filterStaffId.value),
);

const idleMinutes = computed(() => visibleGaps.value.reduce((total, gap) => total + gap.minutes, 0));

const showIdle = computed(() => props.view === 'day' && idleMinutes.value > 0 && (!narrow.value || filterStaffId.value !== null));

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

const formatDay = (value: string) =>
    new Date(`${value}T12:00:00`).toLocaleDateString(undefined, {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    });

const heading = computed(() => formatDay(props.date));

const closedCopy = computed(() => (props.is_today ? 'Closed today.' : 'Closed this day.'));

/** Booking into a gap: the staff member and the minute are already decided. */
const bookGap = (gap: Gap) => {
    form.staff_id = gap.staff_id;
    form.starts_at = `${props.date}T${gap.starts_at}`;
    formError.value = '';
    createOpen.value = true;
};

const offer = (booking: DiaryBooking) => router.get(route('waitlist.index'), { slot: booking.id });

const submit = () => {
    const service = props.services.find((item) => item.id === form.service_id);
    const person = props.staff.find((item) => item.id === form.staff_id);
    const [day, time] = form.starts_at.split('T');
    const [hour, minute] = (time ?? '09:00').split(':').map(Number);
    const end = hour * 60 + minute + (service?.duration_minutes ?? 60);

    // Optimistic until `bookings.store` answers, then swapped in place by
    // `correlation_id` so the row never disappears between temp and real id.
    const correlationId = crypto.randomUUID();
    form.correlation_id = correlationId;

    const temp: DiaryBooking = {
        id: -Date.now(),
        correlation_id: correlationId,
        staff_id: form.staff_id,
        staff_name: person?.name ?? '',
        service_name: service?.name ?? '',
        customer_name: form.customer_name || 'New booking',
        subject_name: form.subject_name || null,
        starts_at_local: `${day} ${timeOf(hour * 60 + minute)}`,
        ends_at_local: `${day} ${timeOf(end)}`,
        status: 'confirmed',
        deposit_status: 'none',
        source: 'manual',
        duration_minutes: service?.duration_minutes ?? null,
        cancellation_reason: null,
    };

    optimistic.value.push(temp);
    createOpen.value = false;
    toast('Booking saved.');

    form.post(route('bookings.store'), {
        preserveScroll: true,
        preserveState: true,
        onError: () => {
            optimistic.value = optimistic.value.filter((booking) => booking.correlation_id !== correlationId);
            formError.value = form.errors.starts_at || 'That time isn’t free. Pick another slot.';
            createOpen.value = true;
        },
        onSuccess: (visit) => {
            const created = visit.props.createdBooking;
            const index = optimistic.value.findIndex((booking) => booking.correlation_id === correlationId);

            if (created?.booking && created.correlation_id === correlationId && index !== -1) {
                optimistic.value[index] = {
                    ...created.booking,
                    correlation_id: correlationId,
                };
            } else if (index !== -1) {
                optimistic.value.splice(index, 1);
            }

            form.reset();
            form.service_id = props.services[0]?.id ?? 0;
            form.rebook_interval_days = props.services[0]?.suggested_interval_days ?? '';
        },
    });
};

const onResize = () => (narrow.value = window.matchMedia('(max-width: 767px)').matches);

onMounted(() => {
    onResize();
    window.addEventListener('resize', onResize);
    clock = setInterval(() => {
        const local = new Date().toLocaleTimeString('en-GB', { hour: '2-digit', minute: '2-digit', timeZone: props.timezone });
        nowLocal.value = local;
    }, 30_000);
});

onUnmounted(() => {
    window.removeEventListener('resize', onResize);
    if (clock) clearInterval(clock);
});

watch(
    () => form.service_id,
    (id) => {
        const service = props.services.find((item) => item.id === Number(id));
        form.rebook_interval_days = service?.suggested_interval_days ?? '';
    },
);

watch(
    () => page.url,
    (url) => {
        if (url.includes('new=1')) createOpen.value = true;
    },
    { immediate: true },
);
</script>

<template>
    <AppLayout>
        <Head title="Diary" />

        <PageHeader :title="heading" :description="`${view === 'week' ? 'Week from ' + range_start : timezone}`">
            <Button @click="createOpen = true">New booking</Button>
        </PageHeader>

        <PendingRequests class="mb-6" :requests="pending_requests" />

        <div class="mb-4 flex flex-wrap items-center gap-2">
            <Button variant="secondary" @click="go(addDays(date, view === 'week' ? -7 : -1))">Earlier</Button>
            <Button variant="secondary" @click="go(page.props.today ?? date)">Today</Button>
            <Button variant="secondary" @click="go(addDays(date, view === 'week' ? 7 : 1))">Later</Button>
            <Button :variant="view === 'day' ? 'primary' : 'secondary'" @click="go(date, 'day')">Day</Button>
            <Button :variant="view === 'week' ? 'primary' : 'secondary'" @click="go(date, 'week')">Week</Button>

            <!--
                The one number, and it earns its place only because the space it
                describes is right underneath it. On its own — which is how it
                used to be shown — "3 h 45 min idle" is a fact nobody can act on.
            -->
            <p v-if="showIdle" class="caption ml-auto">
                <span class="numeral">{{ Math.floor(idleMinutes / 60) }}h {{ idleMinutes % 60 }}m</span> open across
                <span class="numeral">{{ visibleGaps.length }}</span> gaps
            </p>
        </div>

        <EmptyState
            v-if="staff.length === 0"
            title="Nobody is bookable yet"
            description="Add someone who takes appointments and they will show up here as a column."
            action-label="Add staff"
            @action="router.visit(route('staff.index'))"
        />

        <template v-else-if="view === 'day'">
            <p v-if="closed && shown.length === 0" class="caption py-4">
                {{ closedCopy }}
                <Link
                    v-if="next_open"
                    :href="route('diary.index', { date: next_open, view })"
                    class="underline decoration-rule underline-offset-4 hover:decoration-ink"
                >
                    See {{ formatDay(next_open) }}
                </Link>
            </p>

            <template v-else>
                <DayAgenda
                    v-if="narrow"
                    :staff="staff"
                    :bookings="shown"
                    :gaps="gaps"
                    :filter-staff-id="filterStaffId"
                    :now="is_today ? nowLocal : null"
                    empty-copy="No bookings for this day."
                    @open="selected = $event"
                    @book-gap="bookGap"
                    @offer="offer"
                    @filter="filterStaffId = $event"
                />

                <template v-else>
                    <DayGrid
                        v-if="!closed || shown.length > 0"
                        :staff="staff"
                        :bookings="shown"
                        :gaps="gaps"
                        :day-start="bounds.start"
                        :day-end="bounds.end"
                        :now="is_today ? nowLocal : null"
                        @open="selected = $event"
                        @book-gap="bookGap"
                    />

                    <p v-if="closed" class="caption mt-4">
                        {{ closedCopy }}
                        <Link
                            v-if="next_open"
                            :href="route('diary.index', { date: next_open, view })"
                            class="underline decoration-rule underline-offset-4 hover:decoration-ink"
                        >
                            See {{ formatDay(next_open) }}
                        </Link>
                    </p>
                    <p v-else-if="shown.length === 0" class="caption mt-4">No bookings for this day.</p>

                    <!--
                    The freed slots get their action below the grid, where there
                    is room for a real label: a 9rem column cannot hold "Offer to
                    3 waiting" and a truncated call to action is not one.
                -->
                    <ul v-if="freed.length" class="mt-6">
                        <li
                            v-for="booking in freed"
                            :key="`freed-${booking.id}`"
                            class="flex flex-wrap items-baseline gap-4 border-b border-b-rule border-l-2 border-l-accent px-4 py-3"
                        >
                            <span class="numeral w-col-time shrink-0 text-14 font-medium">
                                {{ booking.starts_at_local.slice(11) }}
                            </span>
                            <span class="flex-1 text-14">
                                <span class="font-medium text-accent">Freed —</span>
                                {{ booking.customer_name }} cancelled,
                                <span class="numeral">{{ booking.minutes }}</span> min open with {{ booking.staff_name }}
                            </span>
                            <Button variant="accent" class="shrink-0" @click="offer(booking)">
                                {{
                                    booking.offers_sent
                                        ? `${booking.offers_sent} offer${booking.offers_sent === 1 ? '' : 's'} out`
                                        : booking.waiting
                                          ? `Offer to ${booking.waiting} waiting`
                                          : 'Fill this slot'
                                }}
                            </Button>
                        </li>
                    </ul>
                </template>
            </template>
        </template>

        <!--
            The week. Deliberately the agenda rather than a seven-column grid:
            seven days x four groomers is 28 columns, which is a spreadsheet, and
            the week view's job is "which days are busy", not "what is Priya
            doing at 14:15 on Thursday". That is what the day view is for.
        -->
        <div v-else class="space-y-8">
            <section v-for="day in 7" :key="day">
                <h2 class="border-b border-b-rule pb-2 text-17">
                    {{
                        new Date(`${addDays(range_start, day - 1)}T12:00:00`).toLocaleDateString(undefined, {
                            weekday: 'long',
                            day: 'numeric',
                            month: 'long',
                        })
                    }}
                </h2>
                <DayAgenda
                    :staff="staff"
                    :bookings="shown.filter((b) => b.starts_at_local.slice(0, 10) === addDays(range_start, day - 1))"
                    :gaps="[]"
                    :filter-staff-id="filterStaffId"
                    :now="null"
                    empty-copy="No bookings for this day."
                    @open="selected = $event"
                    @book-gap="bookGap"
                    @offer="offer"
                    @filter="filterStaffId = $event"
                />
            </section>
        </div>

        <SlideOver :show="selected !== null" :title="selected?.customer_name ?? 'Booking'" @close="selected = null">
            <div v-if="selected" class="space-y-2 text-14">
                <p>{{ selected.service_name }}</p>
                <p class="numeral text-ink-2">
                    {{ selected.starts_at_local.slice(11) }} – {{ selected.ends_at_local.slice(11) }}
                </p>
                <p class="text-ink-2">{{ selected.staff_name }}</p>
                <p v-if="selected.subject_name">{{ selected.subject_name }}</p>
                <p v-if="selected.overrun_minutes" class="text-ink-2">
                    Booked for <span class="numeral">{{ selected.duration_minutes }}</span> min, holding
                    <span class="numeral">{{ (selected.duration_minutes ?? 0) + selected.overrun_minutes }}</span> min.
                </p>
                <p v-if="selected.overlapping" class="text-danger">
                    This overlaps another appointment on {{ selected.staff_name }}.
                </p>
                <Link
                    :href="route('bookings.show', selected.id)"
                    class="inline-block text-14 underline decoration-rule underline-offset-4"
                >
                    Open booking
                </Link>
            </div>
        </SlideOver>

        <SlideOver :show="createOpen" title="New booking" @close="createOpen = false">
            <form class="space-y-3" @submit.prevent="submit">
                <p v-if="formError" class="text-13 text-danger" role="alert">{{ formError }}</p>
                <Select
                    v-model="form.service_id"
                    label="Service"
                    :options="services.map((s) => ({ value: s.id, label: s.name }))"
                />
                <Select
                    v-model="form.rebook_interval_days"
                    label="Come back in"
                    :hint="intervalFromService ? 'Usual for this service.' : 'Clear it if this visit should not set a return.'"
                    :options="intervalOptions"
                />
                <Select
                    v-model="form.staff_id"
                    label="Staff"
                    :options="staff.map((s) => ({ value: s.id, label: s.name }))"
                />
                <TextInput v-model="form.starts_at" type="datetime-local" label="Starts" :error="form.errors.starts_at" />
                <TextInput v-model="form.customer_name" label="Client name" :error="form.errors.customer_name" />
                <TextInput v-model="form.customer_email" type="email" label="Email" :error="form.errors.customer_email" />
                <TextInput v-model="form.customer_phone" label="Phone" :error="form.errors.customer_phone" />
                <TextInput v-model="form.subject_name" :label="sentenceCase(page.props.vertical.subject_singular) + ' name'" />
                <Button type="submit" :loading="form.processing">Save booking</Button>
            </form>
        </SlideOver>
    </AppLayout>
</template>
