<script setup lang="ts">
/**
 * One appointment, drawn in a time grid.
 *
 * The whole visual language comes from `dashboard.html`'s timeline, which is
 * the approved one: a flat block on paper, a hairline underneath, and a **2px
 * left border carrying the status**. Nothing is filled — the previous diary
 * filled every block with its staff member's own colour, which turned a
 * monochrome product into a spreadsheet and left status, the thing that
 * actually needs reading, nowhere to live.
 *
 * Past blocks are `ink-2` and carry no detail, exactly as the dashboard's past
 * rows do. The current one takes an ink border and is the only medium-weight
 * text in the grid.
 *
 * Freed slots take the accent, which is the one meaning DESIGN.md rations it
 * for on this screen.
 */
withDefaults(
    defineProps<{
        /** `HH:MM`, already local. */
        time: string;
        title: string;
        /** The service. Hidden on a past block. */
        detail?: string | null;
        tone?: 'confirmed' | 'pending' | 'cancelled' | 'current' | 'freed';
        past?: boolean;
        /** "Runs 30 min over" — a booking longer than its own service. */
        overrunMinutes?: number;
        /** Two appointments on one person at one time. Worth seeing, not hiding. */
        overlapping?: boolean;
        ariaLabel?: string;
    }>(),
    { tone: 'confirmed', past: false, overrunMinutes: 0, overlapping: false },
);

const emit = defineEmits<{ open: [] }>();

const BORDERS = {
    freed: 'border-l-accent',
    cancelled: 'border-l-danger',
    current: 'border-l-ink',
    pending: 'border-l-ink-3',
    confirmed: 'border-l-ink-4',
} as const;
</script>

<template>
    <button
        type="button"
        class="h-full w-full overflow-hidden rounded-none border-b border-b-rule border-l-2 bg-paper px-2 py-1 text-left transition duration-fast ease-product hover:bg-paper-sunk"
        :class="[BORDERS[tone], past ? 'text-ink-2' : 'text-ink']"
        :aria-label="ariaLabel"
        @click="emit('open')"
    >
        <span class="flex items-baseline gap-2">
            <span class="numeral shrink-0 text-12" :class="tone === 'current' ? 'font-medium' : ''">{{ time }}</span>
            <span class="truncate text-12" :class="tone === 'current' ? 'font-medium' : ''">
                <span v-if="tone === 'freed'" class="font-medium text-accent">Freed — </span>{{ title }}
            </span>
        </span>

        <!-- No detail on history. The dashboard's rule, and it holds here. -->
        <span v-if="!past && detail" class="mt-0.5 block truncate text-12 text-ink-2">{{ detail }}</span>

        <span v-if="overrunMinutes" class="mt-0.5 block truncate text-12 text-ink-2">
            Runs <span class="numeral">{{ overrunMinutes }}</span> min over
        </span>

        <!-- The one place `--danger` appears in the grid other than a
             cancellation, because a double-booking is the same class of problem:
             something is wrong and somebody has to do something about it. -->
        <span v-if="overlapping" class="mt-0.5 block truncate text-12 text-danger">Double-booked</span>
    </button>
</template>
