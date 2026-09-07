<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import PendingRequests, { type PendingRequest } from '@/Components/PendingRequests.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import TimelineRow from '@/Components/ui/TimelineRow.vue';
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

/**
 * The overview.
 *
 * **One figure leads.** The band was four cards of roughly equal weight, which
 * is four things to read and no answer to "how is the shop doing". The no-show
 * rate is the headline at 34px because it is the number a deposit policy is
 * decided on; deposits held, overdue and recovered sit beside it at 20px, which
 * is the difference between a reading and a reference.
 *
 * **A rising no-show rate is the accent, and a falling one is ink.** The accent
 * is not "good news" in this product — it is *this is the thing worth doing*,
 * and a no-show rate that has gone up is the one figure on the band a deposit
 * policy gets changed over. `--danger` was the first answer and it was the wrong
 * register: red is a failure that has happened, and a rate creeping from 4.1 to
 * 6.2 over a month has not failed, it has asked a question. The arrow says which
 * way it moved without the colour having to.
 *
 * **Today is a timeline, not a list.** Past appointments are muted and carry no
 * detail; the current one has a 2px ink left border and one extra line; the
 * freed slot is the only coloured row on the screen, and it carries its action
 * inline. All of those are tones on `ui/TimelineRow`, which the diary's 375px
 * agenda also uses — the two screens share the row rather than agreeing about
 * it.
 *
 * **Needs attention aggregates.** Unpaid deposits, a slot freed today, the
 * waitlist and an unconfirmed email were four notices in four places, three of
 * which she had to go looking for. One panel, one line each, and every line
 * opens the screen that clears it.
 *
 * The unpaid deposits carry the accent — its bar and its figure — and the rest
 * of the panel is monochrome. That is one meaning, not two: money requested and
 * not paid is the row on this screen somebody has to act on, and it is the same
 * sentence the bookings list spends its accent on. Under it, a slot freed, a
 * waitlist and an unconfirmed email are all things to know rather than things to
 * do, so they get the hairline bar and secondary ink.
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
    heading: { date: string; tenant: string; staff_today: string; timezone: string };
    band: {
        recovered: { value: string; count: number; month: string; unconfirmed: number };
        overdue: { value: string; count: number; noun: string };
        deposits: { value: string; count: number };
        no_shows: {
            value: string;
            previous: string | null;
            previous_month: string;
            direction: string | null;
            change: string | null;
        };
    };
    today: Row[];
    diary: {
        date: string;
        day: string;
        weekday_plural: string;
        open_today: boolean;
        next_open: { date: string; label: string } | null;
    };
    attention: {
        deposits: { count: number; value: string; oldest_days: number | null };
        waitlist: { count: number; longest_days: number | null };
    };
    pending_requests: PendingRequest[];
}>();

const page = usePage();

const newBooking = () => router.get(route('diary.index'), { new: 1 });

const openWaitlist = (row: Row) => router.get(route('waitlist.index'), { slot: row.id });

const recoveredHint = computed(() => {
    const { count, month, unconfirmed } = props.band.recovered;

    if (count === 0) {
        return `Nothing refilled from the waitlist in ${month} yet.`;
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

const freedToday = computed(() => props.today.filter((row) => row.freed !== null));

const plural = (count: number, word: string) => `${count} ${word}${count === 1 ? '' : 's'}`;

const days = (count: number | null) => (count === null ? '' : count === 0 ? 'today' : plural(count, 'day'));

const waitedFor = (count: number | null) => (count === null ? '—' : `${count} d`);

/**
 * The panel, built in the order a morning is worked: money owed, an hour going
 * empty, people waiting, then the housekeeping. A row with nothing behind it is
 * not drawn — an empty list is the honest state and a row reading "0" is not.
 */
const attentionRows = computed(() => {
    const rows: Array<{
        key: string;
        title: string;
        sub: string;
        value: string;
        href?: string;
        post?: boolean;
        /** The row that carries the panel's accent: money asked for and not paid. */
        strong?: boolean;
    }> = [];

    const { deposits, waitlist } = props.attention;

    if (deposits.count > 0) {
        rows.push({
            key: 'deposits',
            title: `${plural(deposits.count, 'deposit')} unpaid`,
            sub:
                deposits.oldest_days === null
                    ? 'Requested and not yet paid'
                    : `Oldest requested ${days(deposits.oldest_days)}`,
            value: deposits.value,
            href: route('bookings.index', { status: 'pending' }),
            strong: true,
        });
    }

    if (freedToday.value.length > 0) {
        const first = freedToday.value[0];

        rows.push({
            key: 'freed',
            title: `${plural(freedToday.value.length, 'slot')} freed today`,
            sub: `${first.time} · ${first.freed?.minutes} min open after ${first.customer ?? 'somebody'} cancelled`,
            value: `${first.freed?.waiting ?? 0} waiting`,
            href: route('waitlist.index'),
        });
    }

    if (waitlist.count > 0) {
        rows.push({
            key: 'waitlist',
            title: `${waitlist.count} on the waitlist`,
            sub:
                waitlist.longest_days === null
                    ? 'Waiting for a slot'
                    : waitlist.longest_days === 0
                      ? 'Nobody has waited a full day yet'
                      : `Longest waiting ${days(waitlist.longest_days)}`,
            value: waitedFor(waitlist.longest_days),
            href: route('waitlist.index'),
        });
    }

    if (page.props.auth.user && !page.props.auth.user.email_verified_at) {
        rows.push({
            key: 'email',
            title: 'Email not confirmed',
            sub: 'Clients cannot reply to your reminders',
            value: 'Resend',
            href: route('verification.send'),
            post: true,
        });
    }

    return rows;
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

/*
 * "Closed this day" and "nothing booked" are different facts, and the panel has
 * to say which one it is looking at. See `DashboardController::diaryDay`.
 */
const diaryNote = computed(() => (props.diary.open_today ? 'Open today' : 'Closed this day'));

const emptyDescription = computed(() => {
    if (!props.diary.open_today) {
        const next = props.diary.next_open;

        return next === null
            ? `The shop is closed on ${props.diary.weekday_plural}, and no opening hours are set for any other day either.`
            : `The shop is closed on ${props.diary.weekday_plural}. Your next open day is ${next.label}.`;
    }

    return 'A quiet day, or a day nobody has booked yet. The booking page is still taking bookings either way.';
});

const openNextDay = () =>
    router.get(route('diary.index'), props.diary.next_open ? { date: props.diary.next_open.date } : {});
</script>

<template>
    <Head title="Overview" />

    <AppLayout>
        <!--
            **The page is called Overview.** It was titled with the date —
            "Wednesday 26 August" as the `h1` — which is the one heading on the
            screen that never matches the rail item that got you here, and it
            reads as a diary rather than as the summary it is. The redesign has
            it the other way round and that is the right way round: the name of
            the place is the heading, and the day it is showing is the line under
            it, beside the timezone every time on the screen is in.
        -->
        <div class="flex flex-wrap items-baseline justify-between gap-4">
            <div>
                <h1 class="text-20">Overview</h1>
                <p class="caption mt-1">{{ heading.date }} · {{ heading.timezone }}</p>
            </div>
            <Button @click="newBooking">New booking</Button>
        </div>

        <!-- ================================================================
             The band. One reading, three references — see the note above.
             ================================================================ -->
        <div
            class="mt-8 grid gap-6 border-y border-y-rule py-6 md:grid-cols-2 lg:grid-cols-[1.6fr_1fr_1fr_1fr] lg:gap-0"
        >
            <section class="lg:pr-8">
                <h2 class="eyebrow">No-show rate</h2>
                <div class="mt-3 flex flex-wrap items-baseline gap-3">
                    <p class="numeral text-34 text-ink">{{ band.no_shows.value }}</p>
                    <p
                        v-if="band.no_shows.change"
                        class="text-13 font-medium"
                        :class="band.no_shows.direction === 'up' ? 'text-accent' : 'text-ink'"
                    >
                        <span aria-hidden="true">{{ band.no_shows.direction === 'up' ? '▲' : '▼' }}</span>
                        <span class="numeral">{{ band.no_shows.change }}</span>
                        <span class="font-normal text-ink-2"> vs {{ band.no_shows.previous_month }}</span>
                    </p>
                </div>
                <p class="mt-3 text-13 text-ink-2">{{ noShowHint }}</p>
            </section>

            <section class="lg:border-l lg:border-l-rule lg:px-6">
                <h2 class="eyebrow">Deposits held</h2>
                <p class="numeral mt-3 text-20 text-ink">{{ band.deposits.value }}</p>
                <p class="mt-2 text-13 text-ink-2">
                    Across <span class="numeral">{{ band.deposits.count }}</span> bookings · released on completion
                </p>
            </section>

            <section class="lg:border-l lg:border-l-rule lg:px-6">
                <h2 class="eyebrow">Overdue</h2>
                <p class="numeral mt-3 text-20" :class="band.overdue.count === 0 ? 'text-ink-3' : 'text-ink'">
                    {{ band.overdue.value }}
                </p>
                <p class="mt-2 text-13 text-ink-2">
                    <template v-if="band.overdue.count === 0">Nothing outstanding</template>
                    <template v-else>
                        <span class="numeral">{{ band.overdue.count }}</span>
                        {{ band.overdue.noun }}
                        <Link :href="route('overdue.index')" class="ml-1 underline decoration-rule-strong underline-offset-4">
                            Open the list
                        </Link>
                    </template>
                </p>
            </section>

            <section class="lg:border-l lg:border-l-rule lg:pl-6">
                <h2 class="eyebrow">Recovered from waitlist</h2>
                <p class="numeral mt-3 text-20 text-ink">{{ band.recovered.value }}</p>
                <p class="mt-2 text-13 text-ink-2">{{ recoveredHint }}</p>
            </section>
        </div>

        <PendingRequests class="mt-8" :requests="pending_requests" />

        <div class="mt-8 grid items-start gap-8 lg:grid-cols-[1.6fr_1fr]">
            <section>
                <div class="flex items-baseline gap-3 border-b border-b-rule pb-3">
                    <h2 class="text-17">Today’s diary</h2>
                    <p class="text-13 text-ink-2">{{ diaryNote }}</p>
                </div>

                <EmptyState
                    v-if="today.length === 0"
                    class="mt-4"
                    title="Nothing in the diary today"
                    :description="emptyDescription"
                >
                    <div class="flex flex-wrap items-center justify-center gap-4">
                        <Button @click="newBooking">New booking</Button>
                        <QuietAction @click="openNextDay">
                            {{ diary.next_open ? `Open ${diary.next_open.label}` : 'Open the diary' }}
                        </QuietAction>
                    </div>
                </EmptyState>

                <ul v-else class="mt-2">
                    <!--
                        The same `ui/TimelineRow` the diary's 375px agenda is
                        built from. One row component, two screens.
                    -->
                    <!--
                        Every row here is one booking, and every booking has a
                        record. They were inert: a list of appointments you could
                        read and not open, next to a "Needs attention" panel whose
                        every line *is* a link — the same screen teaching two
                        different things about what a row does. A freed slot is
                        the exception and keeps its own inline action, because the
                        booking behind it is the cancelled one and the thing to do
                        with it is offer the slot, not read the cancellation.
                    -->
                    <TimelineRow
                        v-for="row in today"
                        :key="row.id"
                        :time="row.time"
                        :tone="row.freed ? 'freed' : row.current ? 'current' : row.past ? 'past' : 'default'"
                        :meta="row.freed ? null : row.staff"
                        :amount="row.freed ? null : row.amount"
                        :detail="row.detail"
                        :interactive="row.freed === null"
                        :aria-label="`${row.time} — ${row.subject ?? row.customer}, ${row.service}`"
                        @open="router.get(route('bookings.show', row.id))"
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
            </section>

            <section>
                <h2 class="border-b border-b-rule pb-3 text-17">Needs attention</h2>

                <p v-if="attentionRows.length === 0" class="mt-3 text-13 text-ink-2">
                    Nothing waiting on you. Deposits are paid, nobody is on the waitlist and your email is confirmed.
                </p>

                <ul v-else>
                    <li v-for="row in attentionRows" :key="row.key">
                        <!--
                            Every line opens the screen that clears it, so every
                            line is a link. The resend is a POST and says so; it
                            is the one that acts rather than navigates.
                        -->
                        <Link
                            :href="row.href"
                            :method="row.post ? 'post' : 'get'"
                            :as="row.post ? 'button' : 'a'"
                            class="flex w-full items-center gap-3 rounded border-b border-b-rule px-2 py-3 text-left transition duration-fast ease-product hover:bg-paper-sunk"
                        >
                            <!--
                                The bar is an element inside the row, not the
                                row's left border. A border runs the full height
                                including the padding, so it butted into the bar
                                above and below it and the four of them read as
                                one continuous rule with a coloured segment in it.
                                Stretched inside the padding it is a mark on the
                                row, which is what the redesign draws: 3px,
                                rounded, inset top and bottom.
                            -->
                            <span
                                aria-hidden="true"
                                class="w-1 shrink-0 self-stretch rounded"
                                :class="row.strong ? 'bg-accent' : 'bg-rule-strong'"
                            ></span>
                            <span class="min-w-0 flex-1">
                                <span class="block text-14 font-medium text-ink">{{ row.title }}</span>
                                <span class="mt-0.5 block text-12 text-ink-2">{{ row.sub }}</span>
                            </span>
                            <span
                                class="numeral shrink-0 text-13"
                                :class="row.strong ? 'font-medium text-accent-strong' : 'text-ink-2'"
                            >
                                {{ row.value }}
                            </span>
                        </Link>
                    </li>
                </ul>
            </section>
        </div>
    </AppLayout>
</template>
