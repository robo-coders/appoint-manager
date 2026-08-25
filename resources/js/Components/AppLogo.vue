<script setup lang="ts">
/**
 * The Kestrel mark, and the mark locked up with the wordmark.
 *
 * Inlined rather than loaded as a file so it inherits `currentColor` and costs
 * no request. The mark sits at cap height to the left of the wordmark.
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
    }>(),
    { size: 20, variant: 'lockup', label: 'Kestrel', tone: 'ink' },
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
        <span
            v-if="variant === 'lockup'"
            class="font-display font-medium leading-none tracking-20"
            :style="{ fontSize: `${size * 0.95}px` }"
            >Kestrel</span
        >
    </span>
</template>
