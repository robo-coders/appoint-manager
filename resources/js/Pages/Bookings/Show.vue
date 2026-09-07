<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import type { Money } from '@/types/models';

/**
 * One appointment.
 *
 * It was a flat column of eight sentences in a white box — status, deposit,
 * total, who booked it — with nothing saying which of them belonged together.
 * Answering "has this been paid" meant reading all eight, and three of the
 * eight were labels typed into the middle of a sentence.
 *
 * Four groups, and inside each one a label on the left at a fixed width with
 * its value on the right. That is what makes the page scannable: the labels
 * line up, so the eye goes down the left edge to the row it wants and reads
 * across once. Numbers, dates, money and identifiers are mono, from the server,
 * because the server is what knows which of them is a number.
 *
 * Underneath, what has actually been sent about this appointment — read off the
 * message log rather than invented for the page. See
 * `BookingController::detailGroups`.
 */

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

/* The same pill the two lists wear. One status language across the product. */
const tone = computed(() => {
    const status = props.booking.status;

    if (status === 'pending') return 'accent';
    if (status === 'confirmed') return 'confirmed';
    if (status === 'completed') return 'neutral';

    return 'cancelled';
});

/*
 * "Full groom — small dog · Sat 26 Sept, 12:00 · ref BK-1042".
 *
 * It read "Hand strip · Dot" — the service and the pet, and nothing that says
 * *which* appointment this is. Two of the three facts somebody checks against a
 * phone call were missing: when it is, and the reference the customer is reading
 * off their confirmation email. Both are numbers, so both are mono, which is why
 * this is three parts in the template rather than one joined string.
 */
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

/*
 * "3 d left", beside the deposit prompt.
 *
 * The prompt said a deposit was outstanding and stopped there, which leaves the
 * only question it raises — *how long have I got* — for somebody to work out
 * from the Scheduling group further down. Days, not hours: a deposit chase is a
 * thing you do tomorrow morning, and "71 h" is precision nobody acts on.
 */
const daysLeft = computed(() => {
    const starts = new Date(props.booking.starts_at).getTime();
    if (Number.isNaN(starts)) return null;

    const days = Math.ceil((starts - Date.now()) / 86_400_000);

    if (days < 0) return null;
    if (days === 0) return 'today';

    return `${days} d left`;
});

/*
 * "Mark as done" and "Mark as no show" only where they mean something: a
 * confirmed appointment whose start time has passed. A pending request has not
 * been accepted, a cancellation did not happen, and an appointment on Thursday
 * has neither happened nor been missed yet — the server refuses all three for
 * both actions, and a button that is only ever refused is a button that should
 * not be drawn.
 *
 * One computed for both because the two are the same question with two answers:
 * the appointment is over, and the owner is saying which way it went.
 */
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

        <!--
            Capped, like the redesign's record page. Unbounded, the four detail
            groups stretched to whatever the window was, so at 1600px a 96px label
            sat 700px from the value on the other side of the page and the eye had
            to travel the whole width to read one row.
        -->
        <div class="max-w-record">
        <QuietAction :href="route('bookings.index')">← Bookings</QuietAction>

        <!--
            The pill belongs beside the name, not hard right against the page
            edge. `ui/PageHeader` puts its slot in the actions corner, which is
            correct for a control and wrong for a label about the heading — at
            1280 the status ended up 900px from the word it describes.
        -->
        <!--
            **The actions are up here now.** They were four buttons in a row at
            the very bottom of the page, under Activity — so on a booking with any
            history at all, the two things you came to do were below the fold and
            the page ended in a wall of controls. The redesign puts the ones that
            act on the appointment in the header corner beside the name they act
            on, and leaves the destructive one as a quiet underlined phrase at the
            end, which is the register it belongs in.
        -->
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

            <div v-if="booking.status !== 'cancelled'" class="flex shrink-0 flex-wrap items-center gap-2">
                <Button
                    variant="secondary"
                    @click="router.get(route('diary.index'), { date: booking.starts_at_local.slice(0, 10) })"
                >
                    Show in the diary
                </Button>
                <!--
                    The only writer of `BookingStatus::NoShow`. The dashboard's
                    no-show rate has read it since launch and nothing could set
                    it, so the stat was structurally zero — see
                    `BookingService::markNoShow`.
                -->
                <Button v-if="completable" variant="secondary" :loading="markingNoShow" @click="markNoShow">
                    Mark as no show
                </Button>
                <!--
                    The only writer of `BookingStatus::Completed`. It was read in
                    four places and set by nothing but the demo seeders — see
                    `BookingService::complete`.
                -->
                <Button v-if="completable" :loading="completing" @click="complete">Mark as done</Button>
            </div>
        </div>

        <!--
            The deposit prompt, where the thing it is about lives. A booking
            awaiting one is the only state on this page with an outstanding
            question, and it is the page's one accent.
        -->
        <!--
            **No accent on this box.** It carried a 2px accent left border, which
            put the screen's one accent on a *statement of fact* — a deposit is
            outstanding — on a page where the accent is already spent on the
            status pill saying the same thing three inches above it. The redesign
            draws it as a plain hairline box: the countdown is the new
            information, and it is set in mono behind a divider because it is a
            number, not a warning.
        -->
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

        <!--
            The last thing on the page, and the quietest control there is —
            `ui/QuietAction`, which is what the redesign draws under Activity. A
            filled danger button at the end of a record is a control the eye lands
            on every time it reaches the bottom, for the one action nobody is here
            to take.
        -->
        <div v-if="booking.status !== 'cancelled'" class="flex flex-wrap items-center gap-4">
            <QuietAction @click="confirm = 'notify'">Cancel booking</QuietAction>
        </div>

        <p v-else class="text-13 text-ink-2">
            This booking is cancelled.
            <Link :href="route('bookings.index')" class="underline decoration-rule underline-offset-4">
                Back to bookings
            </Link>
        </p>

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
