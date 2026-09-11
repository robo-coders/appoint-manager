<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Money } from '@/types/models';

type DetailRow = { key: string; value: string; mono: boolean };

const props = defineProps<{
    booking: {
        id: number;
        staff_name: string;
        service_name: string;
        customer_name: string;
        subject_name: string | null;
        starts_at_local: string;
        ends_at_local: string;
        status: string;
        deposit_status: string;
        source: string;
        price_at_booking: Money;
        deposit_at_booking: Money;
        is_loyalty_reward: boolean;
        starts_at: string;
        public_token: string;
    };
    groups: Array<{ label: string; rows: DetailRow[] }>;
    activity: Array<{ at: string; text: string }>;
    waitlist_matches: { count: number };
}>();

const confirm = ref<'notify' | 'silent' | null>(null);
const completing = ref(false);
const markingNoShow = ref(false);

const STATUS_LABELS: Record<string, string> = {
    pending: 'Awaiting deposit',
    confirmed: 'Confirmed',
    cancelled: 'Cancelled',
    declined: 'Declined',
    completed: 'Completed',
    no_show: 'No show',
};

const tone = computed(() => {
    const status = props.booking.status;

    if (status === 'pending') return 'accent';
    if (status === 'confirmed') return 'confirmed';
    if (status === 'completed') return 'neutral';

    return 'cancelled';
});

const whenPart = computed(() => {
    const value = props.booking.starts_at_local;
    const date = new Date(`${value.replace(' ', 'T')}:00`);

    return Number.isNaN(date.getTime())
        ? value
        : `${date.toLocaleDateString('en-GB', { weekday: 'short', day: 'numeric', month: 'short' })}, ${value.slice(11)}`;
});

const subtitle = computed(() => {
    const parts = [props.booking.service_name];

    if (props.booking.subject_name) parts.push(props.booking.subject_name);

    return parts.join(' · ');
});

const daysLeft = computed(() => {
    const starts = new Date(props.booking.starts_at).getTime();
    if (Number.isNaN(starts)) return null;

    const days = Math.ceil((starts - Date.now()) / 86_400_000);

    if (days < 0) return null;
    if (days === 0) return 'today';

    return `${days} d left`;
});

const completable = computed(
    () => props.booking.status === 'confirmed' && new Date(props.booking.starts_at) <= new Date(),
);

const complete = () => {
    completing.value = true;
    router.post(route('bookings.complete', props.booking.id), {}, {
        preserveScroll: true,
        onFinish: () => (completing.value = false),
    });
};

const markNoShow = () => {
    markingNoShow.value = true;
    router.post(route('bookings.no-show', props.booking.id), {}, {
        preserveScroll: true,
        onFinish: () => (markingNoShow.value = false),
    });
};

const cancel = (offerWaitlist: boolean) => {
    router.delete(route('bookings.destroy', props.booking.id), {
        data: { offer_waitlist: offerWaitlist },
        onSuccess: () => (confirm.value = null),
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Booking" />

        <div class="max-w-record pb-16 md:pb-0">
        <QuietAction :href="route('bookings.index')">← Bookings</QuietAction>

        <div class="mb-6 mt-1 flex flex-wrap items-start justify-between gap-4">
            <div class="min-w-0">
                <div class="flex flex-wrap items-center gap-3">
                    <h1 class="text-20">{{ booking.customer_name }}</h1>
                    <Badge variant="solid" :tone="tone">{{ STATUS_LABELS[booking.status] ?? booking.status }}</Badge>
                </div>
                <p class="caption mt-1">
                    {{ subtitle }} ·
                    <span class="numeral">{{ whenPart }}</span> ·
                    ref <span class="numeral">#{{ booking.id }}</span>
                </p>
            </div>

            <div v-if="booking.status !== 'cancelled'" class="hidden shrink-0 flex-wrap items-center gap-2 md:flex">
                <Button
                    variant="secondary"
                    @click="router.get(route('diary.index'), { date: booking.starts_at_local.slice(0, 10) })"
                >
                    Show in the diary
                </Button>
                <Button v-if="completable" variant="secondary" :loading="markingNoShow" @click="markNoShow">
                    Mark as no show
                </Button>
                <Button v-if="completable" :loading="completing" @click="complete">Mark as done</Button>
            </div>
        </div>

        <div
            v-if="booking.deposit_status === 'required'"
            class="mb-8 flex flex-wrap items-center gap-3 rounded border border-rule px-3 py-2.5 text-13"
        >
            <span v-if="daysLeft" class="numeral shrink-0 whitespace-nowrap text-12 text-ink-2">{{ daysLeft }}</span>
            <span v-if="daysLeft" aria-hidden="true" class="h-3 w-px shrink-0 bg-rule-strong"></span>
            <span class="text-ink-2">
                A deposit of
                <span class="numeral text-ink">{{ booking.deposit_at_booking.formatted }}</span>
                has not been paid. The slot is held until the appointment.
            </span>
        </div>

        <div class="grid gap-x-12 md:grid-cols-2">
            <section v-for="group in groups" :key="group.label" class="mb-8">
                <h2 class="eyebrow border-b border-b-rule-strong pb-2">{{ group.label }}</h2>
                <dl>
                    <div
                        v-for="row in group.rows"
                        :key="row.key"
                        class="flex items-baseline gap-4 border-b border-b-rule py-3"
                    >
                        <dt class="w-col-staff shrink-0 text-13 text-ink-2">{{ row.key }}</dt>
                        <dd class="min-w-0 flex-1 break-words text-14 text-ink" :class="row.mono ? 'numeral' : ''">
                            {{ row.value }}
                        </dd>
                    </div>
                </dl>
            </section>
        </div>

        <section v-if="activity.length" class="mb-8 max-w-measure">
            <h2 class="eyebrow border-b border-b-rule-strong pb-2">Activity</h2>
            <ul>
                <li v-for="(entry, index) in activity" :key="index" class="flex gap-4 border-b border-b-rule py-3">
                    <span class="numeral w-col-when shrink-0 text-12 text-ink-2">{{ entry.at }}</span>
                    <span class="flex-1 text-13 text-ink">{{ entry.text }}</span>
                </li>
            </ul>
        </section>

        <div v-if="booking.status !== 'cancelled'" class="hidden flex-wrap items-center gap-4 md:flex">
            <QuietAction @click="confirm = 'notify'">Cancel booking</QuietAction>
        </div>

        <p v-else class="text-13 text-ink-2">
            This booking is cancelled.
            <Link :href="route('bookings.index')" class="underline decoration-rule underline-offset-4">
                Back to bookings
            </Link>
        </p>

        </div>

        <div
            v-if="booking.status !== 'cancelled'"
            class="above-tabbar fixed inset-x-0 z-30 flex flex-wrap gap-2 border-t border-t-rule bg-paper px-4 py-3 md:hidden"
        >
            <span class="flex-1 basis-16">
                <Button variant="secondary" block @click="confirm = 'notify'">Cancel</Button>
            </span>
            <span
                v-if="completable"
                class="flex-1 basis-16"
            >
                <Button variant="secondary" block :loading="markingNoShow" @click="markNoShow">No show</Button>
            </span>
            <span v-if="completable" class="flex-1 basis-16">
                <Button block :loading="completing" @click="complete">Mark done</Button>
            </span>
        </div>

        <ConfirmDialog
            :show="confirm === 'notify'"
            title="Cancel this booking?"
            confirm-label="Cancel and text the waitlist"
            @close="confirm = null"
            @confirm="cancel(true)"
        >
            This frees the slot and texts {{ waitlist_matches.count }}
            {{ waitlist_matches.count === 1 ? 'person' : 'people' }} on the waitlist.
        </ConfirmDialog>
        <ConfirmDialog
            :show="confirm === 'silent'"
            title="Cancel without the waitlist?"
            confirm-label="Cancel only"
            @close="confirm = null"
            @confirm="cancel(false)"
        >
            The slot is freed. Nobody on the waitlist is contacted.
        </ConfirmDialog>
    </AppLayout>
</template>
