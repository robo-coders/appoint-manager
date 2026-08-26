<script setup lang="ts">
/**
 * Open time, drawn as space rather than reported as a number.
 *
 * Finding the holes in a day is the operator's daily job. A statistic — "28 min
 * idle" — tells them a hole exists; it does not tell them where it is, how long
 * it is, or who it belongs to, and it cannot be booked into. This is the hole
 * itself: it occupies the minutes it represents, so a 90-minute gap is visibly
 * three times a 30-minute one, and pressing it starts a booking at its first
 * minute for that groomer.
 *
 * It is deliberately almost invisible until you go near it. Empty time is the
 * default state of a diary and a page of outlined boxes would drown the
 * appointments; an ink tint on hover is enough to say "this is a target", and
 * the duration only appears once the gap is tall enough for a line of text —
 * a 15-minute gap labelled "15 min" is a label, not a gap.
 */
withDefaults(
    defineProps<{
        minutes: number;
        /** Announced in full, because the visual is a rectangle. */
        ariaLabel: string;
        /** Below this the gap is drawn but not labelled. */
        labelFrom?: number;
    }>(),
    { labelFrom: 30 },
);

const emit = defineEmits<{ book: [] }>();
</script>

<template>
    <button
        type="button"
        class="group h-full w-full rounded text-left transition duration-fast ease-product hover:bg-ink-tint"
        :aria-label="ariaLabel"
        @click="emit('book')"
    >
        <span
            v-if="minutes >= labelFrom"
            class="numeral flex h-full items-center justify-center text-12 text-ink-3 transition duration-fast ease-product group-hover:text-ink-2"
        >
            {{ minutes }} min
        </span>
    </button>
</template>
