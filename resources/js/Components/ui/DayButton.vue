<script setup lang="ts">
/**
 * One day in the borderless week rail.
 *
 * Only the selected day takes a fill. Containment is a signal and it is spent
 * once: an unselected day sits on the page with a hairline, and a row of seven
 * filled boxes tells you nothing about which one you are on.
 *
 * The fill is **ink, not brand**. A time is a choice the customer is making,
 * not a thing the salon is branding, and brand is rationed to two places on
 * this page — the mark and the primary button.
 *
 * A closed or fully booked day keeps its place, for the same reason a taken
 * time does. Its meaning lives in the accessible name, never in the
 * strike-through alone.
 */
const props = withDefaults(
    defineProps<{
        /** `Mon`, already localised. */
        weekday: string;
        /** Day of the month, as a number. */
        dayOfMonth: string;
        /** The full name for assistive tech: `Saturday 14 March`. */
        fullLabel: string;
        selected?: boolean;
        available?: boolean;
        /** `no times` or `closed` — the two honest reasons. */
        unavailableReason?: string;
    }>(),
    { selected: false, available: true, unavailableReason: 'no times' },
);

const emit = defineEmits<{ pick: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex min-h-row w-full flex-col items-center justify-center rounded transition duration-fast ease-product"
        :class="
            !available
                ? 'border border-rule'
                : selected
                  ? 'bg-ink text-white'
                  : 'border border-rule bg-white hover:border-rule-strong'
        "
        :aria-disabled="!available ? 'true' : undefined"
        :aria-pressed="available ? selected : undefined"
        :aria-label="available ? fullLabel : `${fullLabel}, ${unavailableReason}`"
        @click="available && emit('pick')"
    >
        <span class="text-12" :class="!available ? 'text-ink-4' : selected ? '' : 'text-ink-2'" aria-hidden="true">
            {{ weekday }}
        </span>
        <span class="font-mono text-13" :class="!available ? 'text-ink-4 line-through' : ''" aria-hidden="true">
            {{ dayOfMonth }}
        </span>
    </button>
</template>
