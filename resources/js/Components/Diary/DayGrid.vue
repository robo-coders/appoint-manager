<script setup lang="ts">
import GapButton from '@/Components/ui/GapButton.vue';
import TimeBlock from '@/Components/ui/TimeBlock.vue';
import { computed } from 'vue';
import type { DiaryBooking, Gap, Lane, StaffMember } from './diary';
import { PX_PER_MIN, freedStart, laneFor, minutesOf, timeOf } from './diary';

/**
 * The day, as staff columns.
 *
 * There is no approved mockup for this screen and the three that were built
 * were rejected, so nothing here is invented: every decision is taken from
 * `dashboard.html`'s timeline — which **is** approved — and extended to two
 * dimensions.
 *
 * | dashboard.html | here |
 * |---|---|
 * | past appointments muted, no detail | past blocks at `ink-2`, time and name only |
 * | 2px ink left border on the current one | 2px ink left border, the only medium weight in the grid |
 * | the freed slot is the only coloured row | the freed slot is the only coloured block |
 * | hairline rows | hairline gridlines, on the hour |
 * | mono times | mono times, in a `--col-time` gutter |
 *
 * Two things this screen has that the dashboard does not:
 *
 *   - **Gaps are elements.** See `ui/GapButton` — open time takes up the
 *     minutes it represents and booking into it is one press.
 *   - **Overlaps split the column.** Two appointments on one groomer at once is
 *     a mistake worth seeing, so `laneFor` divides the column between them
 *     rather than drawing one silently on top of the other.
 */
const props = defineProps<{
    staff: StaffMember[];
    bookings: DiaryBooking[];
    gaps: Gap[];
    /** Local `HH:MM`, the first and last edge of the drawn day. */
    dayStart: string;
    dayEnd: string;
    /** Local `HH:MM`, or null when the day on screen is not today. */
    now: string | null;
}>();

const emit = defineEmits<{ open: [DiaryBooking]; bookGap: [Gap] }>();

const startMinutes = computed(() => minutesOf(props.dayStart));
const endMinutes = computed(() => minutesOf(props.dayEnd));
/*
 * Twelve extra pixels at the foot. Gutter labels are centred on their line, so
 * without them the last one — the hour the day ends on — is drawn half outside
 * the box and clipped.
 */
const height = computed(() => (endMinutes.value - startMinutes.value) * PX_PER_MIN + 12);

const hourMarks = computed(() => {
    const marks: number[] = [];

    for (let m = Math.ceil(startMinutes.value / 60) * 60; m <= endMinutes.value; m += 60) marks.push(m);

    return marks;
});

const top = (minutes: number) => (minutes - startMinutes.value) * PX_PER_MIN;

const nowMinutes = computed(() => {
    if (props.now === null) return null;
    const minute = minutesOf(props.now);

    return minute >= startMinutes.value && minute <= endMinutes.value ? minute : null;
});

const forStaff = (staffId: number) => props.bookings.filter((booking) => booking.staff_id === staffId);
const gapsFor = (staffId: number) => props.gaps.filter((gap) => gap.staff_id === staffId);

const lanes = computed(() => {
    const map = new Map<number, Lane>();

    for (const member of props.staff) {
        for (const [id, lane] of laneFor(forStaff(member.id))) map.set(id, lane);
    }

    return map;
});

const geometry = (booking: DiaryBooking) => {
    const lane = lanes.value.get(booking.id) ?? { index: 0, of: 1 };
    /*
     * A freed slot is drawn at the extent of what is genuinely still open, not
     * at the extent of the cancellation. Marek's 15:30–17:00 cancellation has
     * his own 16:30 appointment across its tail; the block that says "freed"
     * must stop where the opportunity does, or it claims an hour that is gone.
     */
    const start = minutesOf(freedStart(booking) ?? booking.starts_at_local.slice(11));
    const end = booking.is_freed
        ? start + (booking.minutes ?? 0)
        : minutesOf(booking.ends_at_local.slice(11));

    return {
        top: `${top(start)}px`,
        // A 15-minute nail clip is 12px tall at this scale, which cannot hold a
        // line of 12px text. Blocks have a floor and short ones overlap the
        // gridline below them a little, which is the lesser of the two problems.
        height: `${Math.max((end - start) * PX_PER_MIN, 22)}px`,
        left: `calc(${(lane.index / lane.of) * 100}% + 2px)`,
        width: `calc(${(1 / lane.of) * 100}% - 4px)`,
    };
};

const toneOf = (booking: DiaryBooking): 'confirmed' | 'pending' | 'cancelled' | 'current' | 'freed' => {
    if (booking.is_freed) return 'freed';
    if (booking.status === 'cancelled') return 'cancelled';
    if (booking.current) return 'current';
    if (booking.status === 'pending') return 'pending';

    return 'confirmed';
};

const nameOf = (booking: DiaryBooking) => booking.subject_name ?? booking.customer_name;
</script>

<template>
    <div class="overflow-x-auto rounded border border-rule bg-white">
        <div
            class="min-w-[640px]"
            :style="{ display: 'grid', gridTemplateColumns: `var(--col-time) repeat(${staff.length}, minmax(9rem, 1fr))` }"
        >
            <div class="sticky left-0 z-20 border-b border-b-rule bg-white" />
            <div
                v-for="member in staff"
                :key="`h-${member.id}`"
                class="border-b border-b-rule border-l border-l-rule px-3 py-2 text-13 font-medium"
            >
                {{ member.name }}
            </div>

            <!-- The time gutter, sticky. A fifth groomer forces a horizontal
                 scroll, and a scrolled grid whose times have gone is a grid of
                 unlabelled rectangles. -->
            <div class="sticky left-0 z-20 bg-white" :style="{ height: `${height}px`, position: 'relative' }">
                <!-- The hour nearest `now` is dropped: two labels 9px apart in
                     a 56px gutter is one unreadable label. Now wins — it is the
                     one that moves. -->
                <span
                    v-for="mark in hourMarks"
                    :key="mark"
                    v-show="nowMinutes === null || Math.abs(mark - nowMinutes) > 20"
                    class="numeral absolute left-0 -translate-y-1/2 bg-white px-2 text-12 text-ink-2"
                    :style="{ top: `${top(mark)}px` }"
                    >{{ timeOf(mark) }}</span
                >
                <span
                    v-if="nowMinutes !== null"
                    class="numeral absolute left-0 -translate-y-1/2 bg-white px-2 text-12 font-medium text-ink"
                    :style="{ top: `${top(nowMinutes)}px` }"
                    >{{ now }}</span
                >
            </div>

            <div
                v-for="member in staff"
                :key="`c-${member.id}`"
                class="relative border-l border-l-rule"
                :style="{ height: `${height}px` }"
            >
                <div
                    v-for="mark in hourMarks"
                    :key="`g-${mark}`"
                    class="absolute inset-x-0 border-t border-t-rule"
                    :style="{ top: `${top(mark)}px` }"
                />

                <div
                    v-for="gap in gapsFor(member.id)"
                    :key="`gap-${gap.starts_at}`"
                    class="absolute inset-x-1"
                    :style="{ top: `${top(minutesOf(gap.starts_at))}px`, height: `${gap.minutes * PX_PER_MIN}px` }"
                >
                    <GapButton
                        :minutes="gap.minutes"
                        :ariaLabel="`${gap.minutes} minutes free with ${member.name} from ${gap.starts_at}. Book it.`"
                        @book="emit('bookGap', gap)"
                    />
                </div>

                <div
                    v-for="booking in forStaff(member.id)"
                    :key="booking.id"
                    class="absolute"
                    :style="geometry(booking)"
                >
                    <TimeBlock
                        :time="booking.starts_at_local.slice(11)"
                        :title="nameOf(booking)"
                        :detail="booking.service_name"
                        :tone="toneOf(booking)"
                        :past="booking.past"
                        :overrun-minutes="booking.overrun_minutes"
                        :overlapping="booking.overlapping"
                        :aria-label="`${booking.starts_at_local.slice(11)} ${nameOf(booking)}, ${booking.service_name}, ${member.name}`"
                        @open="emit('open', booking)"
                    />
                </div>

                <!-- Now, across every column. -->
                <div
                    v-if="nowMinutes !== null"
                    class="pointer-events-none absolute inset-x-0 border-t border-t-ink"
                    :style="{ top: `${top(nowMinutes)}px` }"
                    aria-hidden="true"
                />
            </div>
        </div>
    </div>
</template>
