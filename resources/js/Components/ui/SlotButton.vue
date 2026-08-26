<script setup lang="ts">
/**
 * One time in the fallback picker.
 *
 * Three states, and the third is the one everybody gets wrong: an unavailable
 * time **keeps its place**. Removing taken times leaves a grid of three
 * entries, which cannot tell a customer whether the salon is busy or shut — and
 * a day with nothing left leaves an empty box that reads as a broken page.
 *
 * So unavailable times are struck through, muted to `ink-4` (which WCAG exempts
 * from contrast because it is a disabled control), and carry `aria-disabled`
 * rather than `disabled`. The difference matters: `disabled` takes the element
 * out of the tab order, so a screen-reader user tabbing the grid would hear a
 * different, shorter day than the one on screen. `aria-disabled` leaves it
 * reachable and announced.
 *
 * The strike-through is decoration; the meaning is in the accessible name
 * ("09:15, taken"), because a line through some digits is not information a
 * screen reader can pass on.
 */
const props = withDefaults(
    defineProps<{
        /** `HH:MM`, already in the salon's timezone. */
        time: string;
        selected?: boolean;
        available?: boolean;
        /** Why it cannot be picked. Reaches the accessible name, not the eye. */
        unavailableReason?: string;
    }>(),
    { selected: false, available: true, unavailableReason: 'taken' },
);

const emit = defineEmits<{ pick: [] }>();

const label = () => (props.available ? props.time : `${props.time}, ${props.unavailableReason}`);
</script>

<template>
    <button
        type="button"
        class="min-h-control w-full rounded font-mono text-field transition duration-fast ease-product"
        :class="
            !available
                ? 'border border-rule text-ink-4 line-through'
                : selected
                  ? 'bg-ink text-white'
                  : 'border border-rule bg-white text-ink hover:border-rule-strong'
        "
        :aria-disabled="!available ? 'true' : undefined"
        :aria-pressed="available ? selected : undefined"
        :aria-label="label()"
        @click="available && emit('pick')"
    >
        {{ time }}
    </button>
</template>
