<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import TimelineRow from '@/Components/ui/TimelineRow.vue';
import { computed } from 'vue';
import type { DiaryBooking, Gap, StaffMember } from './diary';
import { minutesOf } from './diary';

const props = defineProps<{
    staff: StaffMember[];
    bookings: DiaryBooking[];
    gaps: Gap[];
    filterStaffId: number | null;
    now: string | null;
    emptyCopy?: string;
}>();

const emit = defineEmits<{
    open: [DiaryBooking];
    bookGap: [Gap];
    offer: [DiaryBooking];
    filter: [number | null];
}>();

const nameOf = (staffId: number) => props.staff.find((member) => member.id === staffId)?.name ?? '';

const rows = computed(() => {
    const visible = (staffId: number) => props.filterStaffId === null || props.filterStaffId === staffId;

    const appointments = props.bookings
        .filter((booking) => visible(booking.staff_id))
        .map((booking) => ({ kind: 'booking' as const, at: minutesOf(booking.starts_at_local.slice(11)), booking }));

    const holes =
        props.filterStaffId === null
            ? []
            : props.gaps
                  .filter((gap) => visible(gap.staff_id))
                  .map((gap) => ({ kind: 'gap' as const, at: minutesOf(gap.starts_at), gap }));

    return [...appointments, ...holes].sort((a, b) => a.at - b.at);
});

const toneOf = (booking: DiaryBooking): 'default' | 'past' | 'current' | 'freed' => {
    if (booking.is_freed) return 'freed';
    if (booking.current) return 'current';
    if (booking.past) return 'past';

    return 'default';
};

const problemRest = (booking: DiaryBooking) => {
    const parts: string[] = [];

    if (booking.overrun_minutes) parts.push(`Runs ${booking.overrun_minutes} min over`);
    if (booking.status === 'cancelled' && !booking.is_freed) {
        parts.push(booking.cancellation_reason ?? 'Cancelled');
    }

    return parts.length ? parts.join(' · ') : null;
};

const hasProblem = (booking: DiaryBooking) => booking.overlapping || problemRest(booking) !== null;

const freedAction = (booking: DiaryBooking) => {
    if (booking.offers_sent) return `${booking.offers_sent} offer${booking.offers_sent === 1 ? '' : 's'} out`;

    return booking.waiting ? `Offer to ${booking.waiting} waiting` : 'Fill this slot';
};
</script>

<template>
    <div>
        <div class="flex flex-wrap gap-2 pb-4" role="group" aria-label="Show one groomer">
            <Button :variant="filterStaffId === null ? 'primary' : 'secondary'" @click="emit('filter', null)">
                Everyone
            </Button>
            <Button
                v-for="member in staff"
                :key="member.id"
                :variant="filterStaffId === member.id ? 'primary' : 'secondary'"
                @click="emit('filter', member.id)"
            >
                {{ member.name.split(' ')[0] }}
            </Button>
        </div>

        <p v-if="filterStaffId === null" class="caption pb-2">Pick one person to see the gaps in their day.</p>

        <p v-if="rows.length === 0 && emptyCopy" class="caption py-4">{{ emptyCopy }}</p>

        <ul v-else>
            <template v-for="row in rows" :key="row.kind === 'gap' ? `g-${row.gap.starts_at}` : row.booking.id">
                <TimelineRow
                    v-if="row.kind === 'gap'"
                    :time="row.gap.starts_at"
                    tone="gap"
                    interactive
                    :aria-label="`${row.gap.minutes} minutes free from ${row.gap.starts_at}. Book it.`"
                    @open="emit('bookGap', row.gap)"
                >
                    <span class="numeral">{{ row.gap.minutes }} min free</span>
                </TimelineRow>

                <TimelineRow
                    v-else
                    :time="row.booking.starts_at_local.slice(11)"
                    :tone="toneOf(row.booking)"
                    :meta="filterStaffId === null ? nameOf(row.booking.staff_id) : null"
                    :detail="null"
                    :interactive="!row.booking.is_freed"
                    @open="emit('open', row.booking)"
                >
                    <template v-if="row.booking.is_freed">
                        {{ row.booking.customer_name }} cancelled,
                        <span class="numeral">{{ row.booking.minutes }}</span> min open
                    </template>
                    <template v-else>
                        {{ row.booking.subject_name ?? row.booking.customer_name }} — {{ row.booking.service_name }}
                    </template>

                    <template v-if="!row.booking.is_freed && hasProblem(row.booking)" #problem>
                        <span v-if="row.booking.overlapping" class="text-danger">Double-booked</span>
                        <span v-if="row.booking.overlapping && problemRest(row.booking)" class="text-ink-2"> · </span>
                        <span v-if="problemRest(row.booking)" class="text-ink-2">{{ problemRest(row.booking) }}</span>
                    </template>

                    <template v-if="row.booking.is_freed" #action>
                        <Button variant="accent" class="shrink-0" @click="emit('offer', row.booking)">
                            {{ freedAction(row.booking) }}
                        </Button>
                    </template>
                </TimelineRow>
            </template>
        </ul>
    </div>
</template>
