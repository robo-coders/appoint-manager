<script setup lang="ts">
/**
 * A hairline row that is a whole choice, not a fragment of one.
 *
 * The alternatives on the booking page are complete appointments: "Wednesday
 * morning" on the left, "09:15 · Marek" muted and mono on the right. Nothing is
 * contained, nothing is filled — the hairline underneath is the only structure,
 * because these are the *quiet* options and a set of three cards would compete
 * with the proposal they are alternatives to.
 *
 * `meta` is mono because it is a time and a name, and the time is a number.
 */
withDefaults(
    defineProps<{
        label: string;
        /** The muted right-hand column. Times, staff, durations. */
        meta?: string;
        /**
         * Accessible name, when the two visible halves do not read as a
         * sentence — "Wednesday morning" plus "09:15 · Marek" is two fragments
         * and a middot, and a screen reader makes hard work of it.
         *
         * WCAG 2.5.3 (Label in Name): whatever is passed **must contain every
         * visible word in the row**, `meta` included — a speech-input user
         * saying what they can see has to activate the control they are looking
         * at. That is a high bar, and most of the time the honest answer is to
         * pass nothing and let the visible text be the name. Lighthouse's
         * `label-content-name-mismatch` audit is what catches getting it wrong.
         */
        ariaLabel?: string;
    }>(),
    {},
);

const emit = defineEmits<{ pick: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex min-h-row w-full items-baseline justify-between gap-3 border-b border-b-rule py-3 text-left transition duration-fast ease-product hover:border-b-rule-strong"
        :aria-label="ariaLabel"
        @click="emit('pick')"
    >
        <span class="text-field">{{ label }}</span>
        <span v-if="meta" class="whitespace-nowrap font-mono text-field text-ink-2">{{ meta }}</span>
    </button>
</template>
