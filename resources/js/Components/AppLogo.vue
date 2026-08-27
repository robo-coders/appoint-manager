<script setup lang="ts">
/**
 * The Appoint Manager mark, and the mark locked up with the wordmark.
 *
 * Inlined rather than loaded as a file so it inherits `currentColor` and costs
 * no request. The mark sits at cap height to the left of the wordmark.
 *
 * **One component owns the lockup, in both of its shapes.** It did not: this
 * file set "Appoint Manager" on one line and `ui/NavRail` hand-rolled a second,
 * stacked copy with its own SVG and its own type. Two files drawing the product
 * name is how the two drift, and the auth surface would have made it three.
 *
 * `stacked` is the rail's shape, and the reason it exists is a measurement
 * rather than a preference: inside a 148px rail with `px-4`, a 20px mark and an
 * 8px gap there are 88px left, and "Appoint Manager" sets 104.63px at 13px/500
 * in the real Geist face. It does not fit and does not nearly fit. Where there
 * is room — the auth pages, the admin console — the name sets on one line,
 * because two lines is what a narrow column costs, not what the wordmark is.
 */
withDefaults(
    defineProps<{
        /** Height of the mark in px. The wordmark scales from this. */
        size?: number;
        variant?: 'mark' | 'lockup';
        /**
         * `ink` everywhere in the admin app. `brand` only on the public booking
         * page, where the mark takes the salon's colour.
         */
        tone?: 'ink' | 'brand';
        /** Accessible name. Set to '' when the logo sits inside a labelled link. */
        label?: string;
        /**
         * The 148px rail's two-line set. Fixed 13px rather than derived from
         * `size`, because the measurement above is a 13px measurement.
         */
        stacked?: boolean;
    }>(),
    { size: 20, variant: 'lockup', label: 'Appoint Manager', tone: 'ink', stacked: false },
);
</script>

<template>
    <span
        class="inline-flex items-center text-ink"
        :style="{ gap: `${size * 0.42}px` }"
        :role="label ? 'img' : undefined"
        :aria-label="label || undefined"
        :aria-hidden="label ? undefined : 'true'"
    >
        <svg
            :width="size"
            :height="size"
            viewBox="0 0 24 24"
            fill="none"
            aria-hidden="true"
            focusable="false"
            class="shrink-0"
            :class="tone === 'brand' ? 'text-brand' : 'text-ink'"
        >
            <path fill="currentColor" d="M0 0h16.66L4.66 24H0Z" />
            <path fill="currentColor" d="M19.34 0H24v24H7.34Z" />
        </svg>
        <span v-if="variant === 'lockup' && stacked" class="text-13 font-medium leading-none">
            Appoint<br />Manager
        </span>
        <span
            v-else-if="variant === 'lockup'"
            class="whitespace-nowrap font-display font-medium leading-none tracking-20"
            :style="{ fontSize: `${size * 0.95}px` }"
            >Appoint Manager</span
        >
    </span>
</template>
