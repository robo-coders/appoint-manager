<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import DayButton from '@/Components/ui/DayButton.vue';
import Skeleton from '@/Components/ui/Skeleton.vue';
import SlotButton from '@/Components/ui/SlotButton.vue';
import { computed } from 'vue';

export type Slot = {
    starts_at: string;
    starts_at_local: string;
    staff_ids: number[];
    available: boolean;
    half: 'am' | 'pm';
};

const props = defineProps<{
    week: string[];
    days: Record<string, Slot[]>;
    selectedDate: string | null;
    selectedStartsAt: string | null;
    loading?: boolean;
    context: string;
}>();

const emit = defineEmits<{
    pickDay: [string];
    pickSlot: [Slot];
    shiftWeek: [number];
}>();

const parts = (iso: string) => {
    const date = new Date(`${iso}T12:00:00`);

    return {
        weekday: date.toLocaleDateString(undefined, { weekday: 'short' }),
        dayOfMonth: String(date.getDate()),
        full: date.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }),
    };
};

const slotsFor = (iso: string | null) => (iso ? (props.days[iso] ?? []) : []);

const morning = computed(() => slotsFor(props.selectedDate).filter((slot) => slot.half === 'am'));
const afternoon = computed(() => slotsFor(props.selectedDate).filter((slot) => slot.half === 'pm'));
const nothingAtAll = computed(() => morning.value.length === 0 && afternoon.value.length === 0);

const dayState = (iso: string) => {
    const slots = props.days[iso];

    if (slots === undefined) {
        return { available: true, reason: '' };
    }

    if (slots.length === 0) {
        return { available: false, reason: 'closed' };
    }

    return slots.some((slot) => slot.available)
        ? { available: true, reason: '' }
        : { available: false, reason: 'no times' };
};

const weekLabel = computed(() => {
    const first = props.week[0];

    return first ? `Week of ${parts(first).full}` : 'Week';
});
</script>

<template>
    <section class="appear">
        <p class="caption">{{ context }}</p>
        <h2 class="mt-1 text-20 font-medium">Pick a day</h2>

        <div class="mt-4 flex items-center justify-between gap-2">
            <Button variant="ghost" @click="emit('shiftWeek', -1)">Earlier</Button>
            <p class="caption">{{ weekLabel }}</p>
            <Button variant="ghost" @click="emit('shiftWeek', 1)">Later</Button>
        </div>

        <div class="mt-2 grid grid-cols-7 gap-1" role="group" :aria-label="weekLabel">
            <DayButton
                v-for="iso in week"
                :key="iso"
                :weekday="parts(iso).weekday"
                :day-of-month="parts(iso).dayOfMonth"
                :full-label="parts(iso).full"
                :selected="iso === selectedDate"
                :available="dayState(iso).available"
                :unavailable-reason="dayState(iso).reason"
                @pick="emit('pickDay', iso)"
            />
        </div>

        <div v-if="loading" class="mt-6" aria-busy="true">
            <p class="sr-only">Loading times</p>
            <div class="grid grid-cols-3 gap-2" aria-hidden="true">
                <Skeleton v-for="n in 9" :key="n" shape="bar" />
            </div>
        </div>

        <template v-else>
            <template v-if="morning.length">
                <h3 class="caption mt-6 border-b border-b-rule pb-2">Morning</h3>
                <div class="mt-3 grid grid-cols-3 gap-2" role="group" aria-label="Morning times">
                    <SlotButton
                        v-for="slot in morning"
                        :key="slot.starts_at"
                        :time="slot.starts_at_local"
                        :available="slot.available"
                        :selected="slot.starts_at === selectedStartsAt"
                        @pick="emit('pickSlot', slot)"
                    />
                </div>
            </template>

            <template v-if="afternoon.length">
                <h3 class="caption mt-6 border-b border-b-rule pb-2">Afternoon</h3>
                <div class="mt-3 grid grid-cols-3 gap-2" role="group" aria-label="Afternoon times">
                    <SlotButton
                        v-for="slot in afternoon"
                        :key="slot.starts_at"
                        :time="slot.starts_at_local"
                        :available="slot.available"
                        :selected="slot.starts_at === selectedStartsAt"
                        @pick="emit('pickSlot', slot)"
                    />
                </div>
            </template>

            <p v-if="nothingAtAll" class="mt-6 text-15 text-ink-2">
                Closed this day. Try another, or join the waitlist below.
            </p>
        </template>
    </section>
</template>
