<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import CustomerNotesPanel from '@/Components/CustomerNotesPanel.vue';
import CustomerSuggestedRule from '@/Components/CustomerSuggestedRule.vue';
import VisitCadenceTimeline, { type CadenceMark } from '@/Components/VisitCadenceTimeline.vue';
import VisitLedgerTable, { type LedgerRow } from '@/Components/VisitLedgerTable.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import HiddenContact from '@/Components/ui/HiddenContact.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import Stat from '@/Components/ui/Stat.vue';
import type { Money, Paginated } from '@/types/models';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    customer: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        has_email: boolean;
        contact_hidden: boolean;
        requires_full_payment: boolean;
        subjects: Array<{ id: number; name: string; descriptor: string | null }>;
    };
    badge: { label: string; tone: 'neutral' | 'watch' } | null;
    stats: {
        visits: number;
        no_shows: number;
        lifetime_value: Money;
        average_gap_days: number | null;
        first_visit_on: string | null;
        next_visit: {
            booking_id: number;
            date: string;
            day_label: string;
            time: string;
            service_name: string | null;
        } | null;
    };
    attendance: {
        percentage: number | null;
        kept: number;
        booked: number;
        strip: Array<'attended' | 'no_show'>;
        summary: string;
        deposit_behaviour: string;
        books_through: string | null;
        usual_slot: string | null;
    };
    cadence: CadenceMark[];
    suggestedRule: { no_show_count: number; window_months: number; message: string } | null;
    notes: { text: string | null; editor_name: string | null; updated_at: string | null };
    ledger: Paginated<LedgerRow>;
    ledgerExpanded: boolean;
    loyalty: {
        package_name: string | null;
        reward: string | null;
        sessions_required: number | null;
        stamps_used: number;
        remaining: number;
        reward_due: boolean;
        earning: boolean;
        cycles_completed: number;
        free_sessions: Array<{
            id: number;
            service_name: string | null;
            starts_at_local: string | null;
            status: string;
        }>;
    } | null;
}>();

const dayMonthYear = (iso: string) => {
    const date = new Date(`${iso}T12:00:00`);

    return Number.isNaN(date.getTime())
        ? iso
        : date.toLocaleDateString('en-GB', { day: 'numeric', month: 'short', year: 'numeric' });
};

const visitsHint = computed(() =>
    props.stats.visits === 0
        ? 'No visits yet'
        : props.stats.first_visit_on
          ? `Since ${dayMonthYear(props.stats.first_visit_on)}`
          : 'Kept and missed appointments',
);

const gapValue = computed(() =>
    props.stats.average_gap_days === null ? '—' : `${props.stats.average_gap_days}d`,
);

const gapHint = computed(() =>
    props.stats.average_gap_days === null ? 'Needs two visits' : 'Between visits',
);

const nextHint = computed(() => {
    const next = props.stats.next_visit;

    if (next === null) return 'Nothing booked';

    return [next.service_name, next.time].filter(Boolean).join(', ');
});

const messageHref = computed(() =>
    !props.customer.contact_hidden && props.customer.phone ? `sms:${props.customer.phone}` : undefined,
);

const attendanceValue = computed(() =>
    props.attendance.percentage === null ? '—' : `${props.attendance.percentage}%`,
);

const attendanceSub = computed(() =>
    props.attendance.percentage === null
        ? 'Not enough history yet'
        : `${props.attendance.kept} kept of ${props.attendance.booked} booked`,
);

const signals = computed(() =>
    [
        { key: 'Deposit behaviour', value: props.attendance.deposit_behaviour },
        { key: 'Books through', value: props.attendance.books_through },
        { key: 'Usual slot', value: props.attendance.usual_slot },
        props.customer.requires_full_payment
            ? { key: 'Payment', value: 'Full amount up front' }
            : null,
    ].filter((signal): signal is { key: string; value: string } => signal !== null && signal.value !== null),
);
</script>

<template>
    <AppLayout>
        <Head :title="customer.name" />

        <QuietAction :href="route('customers.index')">← Customers</QuietAction>

        <PageHeader :title="customer.name">
            <div class="flex flex-wrap items-center justify-end gap-2">
                <Badge v-if="badge" :tone="badge.tone === 'watch' ? 'cancelled' : 'confirmed'">{{ badge.label }}</Badge>
                <Button v-if="messageHref" variant="secondary" class="whitespace-nowrap" :href="messageHref">
                    Message
                </Button>
                <Button class="whitespace-nowrap" @click="router.get(route('diary.index'), { new: 1 })">
                    Book a visit
                </Button>
            </div>
        </PageHeader>

        <section class="rounded border border-rule bg-white p-4">
            <p class="numeral text-13 text-ink-2">
                <HiddenContact v-if="customer.contact_hidden && customer.phone" :masked="customer.phone" verbose />
                <template v-else-if="customer.phone">{{ customer.phone }}</template>
                <template v-else>No phone</template>
            </p>
            <p class="mt-1 text-13 text-ink-2">
                <HiddenContact v-if="customer.contact_hidden && customer.has_email" verbose />
                <template v-else>{{ customer.email || 'No email' }}</template>
            </p>

            <ul class="mt-2 space-y-0.5 text-13 text-ink">
                <li v-for="subject in customer.subjects" :key="subject.id">
                    {{ subject.name }}
                    <span v-if="subject.descriptor" class="text-ink-2">· {{ subject.descriptor }}</span>
                </li>
                <li v-if="customer.subjects.length === 0" class="text-ink-2">Nothing on file yet</li>
            </ul>

            <div class="mt-4 grid grid-cols-2 gap-4 border-t border-rule pt-4 sm:grid-cols-3 lg:grid-cols-5">
                <Stat label="Visits" :value="String(stats.visits)" :hint="visitsHint" />
                <Stat
                    label="No-shows"
                    :value="String(stats.no_shows)"
                    :hint="stats.no_shows === 0 ? 'Never missed' : 'Missed without cancelling'"
                />
                <Stat
                    label="Lifetime value"
                    :value="stats.lifetime_value.formatted"
                    :hint="stats.visits === 0 ? 'Nothing paid yet' : 'Paid to date'"
                />
                <Stat label="Average gap" :value="gapValue" :hint="gapHint" />
                <Stat
                    label="Next visit"
                    :value="stats.next_visit ? stats.next_visit.day_label : '—'"
                    :hint="nextHint"
                />
            </div>
        </section>

        <div class="mt-4 grid gap-4 lg:grid-cols-3 lg:items-start">
            <div class="flex flex-col gap-4 lg:col-span-2">
                <VisitCadenceTimeline :visits="cadence" />

                <VisitLedgerTable :customer-id="customer.id" :visits="ledger" :expanded="ledgerExpanded" />
            </div>

            <div class="flex flex-col gap-4">
                <section class="rounded border border-rule bg-white p-4">
                    <h2 class="text-14 font-medium">Attendance</h2>

                    <div class="mt-3 flex flex-wrap items-baseline gap-3">
                        <span
                            class="numeral text-24"
                            :class="stats.no_shows > 0 ? 'text-danger' : 'text-ink'"
                            data-testid="attendance-percentage"
                            >{{ attendanceValue }}</span
                        >
                        <span class="text-13 text-ink-2">{{ attendanceSub }}</span>
                    </div>

                    <div v-if="attendance.strip.length" class="mt-3 flex h-2 gap-1" aria-hidden="true">
                        <span
                            v-for="(outcome, index) in attendance.strip"
                            :key="index"
                            class="flex-1 rounded"
                            :class="outcome === 'no_show' ? 'border border-danger' : 'bg-ink'"
                        />
                    </div>

                    <p class="mt-3 text-13 text-ink-2">{{ attendance.summary }}</p>

                    <dl class="mt-4 space-y-2 border-t border-rule pt-4">
                        <div v-for="signal in signals" :key="signal.key" class="flex items-baseline justify-between gap-4">
                            <dt class="text-13 text-ink-2">{{ signal.key }}</dt>
                            <dd class="text-right text-13 text-ink">{{ signal.value }}</dd>
                        </div>
                    </dl>
                </section>

                <CustomerSuggestedRule v-if="suggestedRule" :customer-id="customer.id" :rule="suggestedRule" />

                <CustomerNotesPanel
                    :customer-id="customer.id"
                    :text="notes.text"
                    :editor-name="notes.editor_name"
                    :updated-at="notes.updated_at"
                />

                <section v-if="loyalty" class="rounded border border-rule bg-white p-4">
                    <div class="flex flex-wrap items-baseline justify-between gap-3">
                        <h2 class="text-14 font-medium">{{ loyalty.package_name ?? 'Loyalty' }}</h2>
                        <Badge v-if="!loyalty.earning" tone="neutral">Paused</Badge>
                        <Badge v-else-if="loyalty.reward_due" tone="accent">Next one is free</Badge>
                        <Badge v-else tone="confirmed">Collecting</Badge>
                    </div>

                    <p class="mt-2 text-14">
                        <span class="numeral">{{ loyalty.stamps_used }}</span>
                        of
                        <span class="numeral">{{ loyalty.sessions_required ?? 0 }}</span>
                        stamps used
                    </p>

                    <p class="mt-1 text-13 text-ink-2">
                        <template v-if="!loyalty.earning">
                            The package behind this card is switched off, so it is not collecting. Switching loyalty
                            back on in settings moves them onto the current package and keeps what they have already
                            earned.
                        </template>
                        <template v-else-if="loyalty.reward_due">
                            {{ loyalty.reward ?? 'The next session is free' }}. Their next booking is priced at zero
                            and skips the deposit on its own — there is nothing to do here.
                        </template>
                        <template v-else>
                            <span class="numeral">{{ loyalty.remaining }}</span>
                            more until {{ (loyalty.reward ?? 'the next session is free').toLowerCase() }}.
                        </template>
                    </p>

                    <p v-if="loyalty.cycles_completed > 0" class="mt-4 text-13 text-ink-2">
                        <span class="numeral">{{ loyalty.cycles_completed }}</span>
                        full card{{ loyalty.cycles_completed === 1 ? '' : 's' }} so far.
                    </p>

                    <ul v-if="loyalty.free_sessions.length" class="mt-2 space-y-1 text-13">
                        <li v-for="session in loyalty.free_sessions" :key="session.id">
                            <Link :href="route('bookings.show', session.id)" class="underline">
                                {{ session.starts_at_local }} · {{ session.service_name }}
                            </Link>
                            <span class="text-ink-2"> free · {{ session.status }}</span>
                        </li>
                    </ul>
                </section>

                <section class="rounded border border-rule bg-white p-4">
                    <h2 class="text-14 font-medium">Their data</h2>
                    <div class="mt-3 flex flex-wrap gap-4 text-13">
                        <a :href="route('customers.export', customer.id)" class="underline">Export data</a>
                        <Link
                            :href="route('customers.destroy', customer.id)"
                            method="delete"
                            as="button"
                            class="text-ink-2 underline"
                        >
                            Delete record
                        </Link>
                    </div>
                </section>
            </div>
        </div>
    </AppLayout>
</template>
