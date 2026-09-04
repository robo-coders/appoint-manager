<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PendingRequests, { type PendingRequest } from '@/Components/PendingRequests.vue';
import TimelineRow from '@/Components/ui/TimelineRow.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The overview. `public/mockups/dashboard.html` is the target.
 *
 * Two ideas, and both of them are about weight.
 *
 * **The band is not four equal cards.** Recovered and overdue take the weight
 * (sunk, 34px mono); deposits and no-shows stay 20px on paper with a hairline.
 * What recovered counts is documented on `DashboardController::recovered()`,
 * because a sales pitch that is not exact is a lie. Overdue is the sum of each
 * subject's usual service price — see `OverdueSubjects`.
 *
 * **Today is a timeline, not a list.** Past appointments are muted and carry no
 * detail; the current one has a 2px ink left border and one extra line; the
 * freed slot is the only coloured row on the screen, and it carries its action
 * inline. Everything else is a hairline row. All five of those are tones on
 * `ui/TimelineRow`, which the diary's 375px agenda also uses — the two screens
 * share the row rather than agreeing about it.
 */

type Freed = { minutes: number; waiting: number; offers_sent: number; deposit_kept: boolean };

type Row = {
    id: number;
    time: string;
    customer: string | null;
    subject: string | null;
    service: string | null;
    staff: string | null;
    amount: string;
    status: string;
    past: boolean;
    current: boolean;
    detail: string | null;
    freed: Freed | null;
};

const props = defineProps<{
    heading: { date: string; tenant: string; staff_today: string };
    band: {
        recovered: { value: string; count: number; month: string; unconfirmed: number };
        overdue: { value: string; count: number; noun: string };
        deposits: { value: string; count: number };
        no_shows: { value: string; previous: string | null; previous_month: string; direction: string | null };
    };
    today: Row[];
    pending_requests: PendingRequest[];
}>();

const newBooking = () => router.get(route('diary.index'), { new: 1 });

const openWaitlist = (row: Row) => router.get(route('waitlist.index'), { slot: row.id });

const recoveredHint = computed(() => {
    const { count, month, unconfirmed } = props.band.recovered;

    if (count === 0) {
        return `Nothing has been refilled from the waitlist in ${month} yet.`;
    }

    const base = `From ${count} appointment${count === 1 ? '' : 's'} refilled in ${month}.`;

    return unconfirmed === 0
        ? base
        : `${base} ${unconfirmed === 1 ? 'One is' : `${unconfirmed} are`} still unconfirmed.`;
});

const noShowHint = computed(() => {
    const { previous, previous_month, direction } = props.band.no_shows;

    if (previous === null) {
        return `Nothing to compare with in ${previous_month}`;
    }

    return `${direction === 'down' ? 'Down' : 'Up'} from ${previous} in ${previous_month}`;
});

/**
 * The label on the freed slot's one action.
 *
 * "Offer to 3 waiting" is a thing to do. "3 offers out" is a thing that has
 * already happened, and pressing the same button again would text nobody — so
 * it says so rather than promising an action it cannot perform.
 */
const freedAction = (freed: Freed) => {
    if (freed.offers_sent > 0) {
        return `${freed.offers_sent} offer${freed.offers_sent === 1 ? '' : 's'} out`;
    }

    return freed.waiting > 0 ? `Offer to ${freed.waiting} waiting` : 'Fill this slot';
};

const freedLine = (row: Row) => {
    const kept = row.freed?.deposit_kept ? ', deposit kept' : '';

    return `${row.customer ?? 'Someone'} cancelled, ${row.freed?.minutes} min open${kept}`;
};
</script>

<template>
    <Head title="Overview" />

    <AppLayout>
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <div>
                <h1 class="text-20">{{ heading.date }}</h1>
                <p class="caption mt-1">{{ heading.tenant }} · {{ heading.staff_today }}</p>
            </div>
            <Button @click="newBooking">New booking</Button>
        </div>

        <!-- ================================================================
             The weighted band. 45 / 27 / 28, not four quarters.
             ================================================================ -->
        <div class="mt-8 grid gap-6 md:grid-cols-[34fr_32fr_17fr_17fr]">
            <section class="rounded border border-rule bg-paper-sunk p-6">
                <h2 class="caption">Recovered from waitlist</h2>
                <p class="numeral mt-2 text-34 font-medium">{{ band.recovered.value }}</p>
                <p class="mt-2 text-13 text-ink-2">{{ recoveredHint }}</p>
            </section>

            <section class="rounded border border-rule bg-paper-sunk p-6">
                <h2 class="caption">Overdue</h2>
                <p class="numeral mt-2 text-34 font-medium">{{ band.overdue.value }}</p>
                <p class="mt-2 text-13 text-ink-2">
                    <span class="numeral">{{ band.overdue.count }}</span>
                    {{ band.overdue.noun }}
                    <Link :href="route('overdue.index')" class="ml-2 underline decoration-rule-strong underline-offset-4">
                        Open the list
                    </Link>
                </p>
            </section>

            <section class="rounded border border-rule p-6">
                <h2 class="caption">Deposits held</h2>
                <p class="numeral mt-2 text-20 font-medium">{{ band.deposits.value }}</p>
                <p class="mt-2 text-13 text-ink-2">
                    Across <span class="numeral">{{ band.deposits.count }}</span> bookings
                </p>
            </section>

            <section class="rounded border border-rule p-6">
                <h2 class="caption">No-show rate</h2>
                <p class="numeral mt-2 text-20 font-medium">{{ band.no_shows.value }}</p>
                <p class="mt-2 text-13 text-ink-2">{{ noShowHint }}</p>
            </section>
        </div>

        <PendingRequests class="mt-8" :requests="pending_requests" />

        <h2 class="mt-8 border-b border-b-rule pb-3 text-17">Today</h2>

        <EmptyState
            v-if="today.length === 0"
            class="mt-4"
            title="Nothing in the diary today"
            description="A quiet day, or a day nobody has booked yet. The booking page is still taking bookings either way."
            action-label="Open the diary"
            @action="router.get(route('diary.index'))"
        />

        <ul v-else>
            <!--
                The same `ui/TimelineRow` the diary's 375px agenda is built from.
                One row component, two screens: the brief's constraint for the
                diary was consistency with this timeline, and the strongest form
                of that is not writing it twice.
            -->
            <TimelineRow
                v-for="row in today"
                :key="row.id"
                :time="row.time"
                :tone="row.freed ? 'freed' : row.current ? 'current' : row.past ? 'past' : 'default'"
                :meta="row.freed ? null : row.staff"
                :amount="row.freed ? null : row.amount"
                :detail="row.detail"
            >
                <template v-if="row.freed">{{ freedLine(row) }}</template>
                <template v-else>{{ row.subject ?? row.customer }} — {{ row.service }}</template>

                <template v-if="row.freed" #action>
                    <Button variant="accent" class="shrink-0" @click="openWaitlist(row)">
                        {{ freedAction(row.freed) }}
                    </Button>
                </template>
            </TimelineRow>
        </ul>
    </AppLayout>
</template>
