<script setup lang="ts">
/**
 * The product mark, and the mark locked up with the wordmark.
 *
 * Inlined rather than loaded as a file so it inherits `currentColor` and costs
 * no request. The mark sits at cap height to the left of the wordmark.
 *
 * **One component owns the lockup, in both of its shapes.** It did not: this
 * file set the name on one line and `ui/NavRail` hand-rolled a second, stacked
 * copy with its own SVG and its own type. Two files drawing the product name is
 * how the two drift, and the auth surface would have made it three.
 *
 * **The name is never written here.** It comes from `config('product.name')`
 * through Inertia's shared props, because a wordmark is the most visible place
 * a product is named and the least likely to be remembered when it is renamed.
 * This component only ever renders inside the Inertia app — the rail, the auth
 * pages, onboarding and the specimen gallery — so the shared prop is always
 * there. The public booking page does not use it; that surface wears the salon's
 * name, not ours.
 *
 * `stacked` is the rail's shape, and it exists for a measurement rather than a
 * preference: inside a 148px rail with `px-4`, a 20px mark and an 8px gap there
 * are 88px left. "Appoint Manager" set 104.63px at 13px/500 in the real Geist
 * face — it did not fit and did not nearly fit, so the rail took two lines.
 * "DiaryDesk" is nine characters and fits, which is why the split is now
 * derived from the name rather than hardcoded as a `<br>`: a one-word name
 * takes one line and a two-word name takes two, without this file having an
 * opinion about which name it is.
 */
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /** Height of the mark in px. The wordmark scales from this. */
        size?: number;
        variant?: 'mark' | 'lockup';
        /**
         * `ink` everywhere in the admin app. `brand` only on the public booking
         * page, where the mark takes the salon's colour.
         */
        tone?: 'ink' | 'brand';
        /**
         * Accessible name. Defaults to the product's name. Set to '' when the
         * logo sits inside a link that is already labelled.
         */
        label?: string;
        /**
         * The 148px rail's narrow set. Fixed 13px rather than derived from
         * `size`, because the measurement above is a 13px measurement.
         */
        stacked?: boolean;
    }>(),
    { size: 20, variant: 'lockup', tone: 'ink', stacked: false },
);

const name = computed(() => (usePage().props.appName as string) ?? '');

/** `label` is optional, so `undefined` means "use the name" and '' means "none". */
const ariaLabel = computed(() => (props.label === undefined ? name.value : props.label));

/**
 * The name broken for the rail. One word stays one line; anything longer wraps
 * at its spaces rather than mid-word.
 */
const lines = computed(() => name.value.split(/\s+/).filter(Boolean));
</script>

<template>
    <span
        class="inline-flex items-center text-ink"
        :style="{ gap: `${size * 0.42}px` }"
        :role="ariaLabel ? 'img' : undefined"
        :aria-label="ariaLabel || undefined"
        :aria-hidden="ariaLabel ? undefined : 'true'"
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
            <template v-for="(line, i) in lines" :key="line">
                <br v-if="i > 0" />{{ line }}
            </template>
        </span>
        <span
            v-else-if="variant === 'lockup'"
            class="whitespace-nowrap font-display font-medium leading-none tracking-20"
            :style="{ fontSize: `${size * 0.95}px` }"
            >{{ name }}</span
        >
    </span>
</template>
