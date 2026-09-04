<script setup lang="ts">
/**
 * The product mark, and the mark locked up with the wordmark.
 *
 * **It is artwork now, not markup.** This file used to draw a two-path SVG by
 * hand and set the product's name beside it as live type read from
 * `config('product.name')`. There is a real logo, so both halves are gone: the
 * lockup is `assets/logo.svg` and the mark is `assets/icon.svg`, imported so
 * Vite hashes them and serves them from the manifest like every other asset.
 *
 * What that costs, written down because it is a genuine loss rather than an
 * oversight: the wordmark no longer follows a rename. `product.name` is still
 * the only place the name is *typed*, and it still names the title, the chrome,
 * the emails and the manifest — but the name inside the artwork is drawn, the
 * way a logo's name always is, and renaming the product now means new artwork.
 * The configured name is still what assistive tech reads, from `label` below,
 * so the two can be seen to disagree rather than disagreeing silently.
 *
 * **One component owns the lockup, in all four of its files.** The rail, the
 * auth pages, onboarding and the gallery all come through here, so no surface
 * picks its own file and no surface gets the light logo on a dark ground.
 *
 * `reversed` is the choice between the two colourways, and it is a property of
 * the *background*, not a preference: `logo.svg` is drawn in --ink and vanishes
 * on anything dark; `logo-reversed.svg` is drawn in --paper and vanishes on
 * anything light. Nothing in the product is dark today — the rail is
 * --paper-sunk, the auth column and the marketing footer are paper — so
 * `reversed` is currently exercised only by `/dev/components`, on the ink
 * swatch there. It is wired because the alternative is a surface inventing its
 * own `<img>` the first time something ships on ink.
 *
 * The public booking page deliberately does not use this component. That
 * surface wears the salon's initial in the salon's colour; ours is the one logo
 * that must not appear on a customer-facing shopfront. See DESIGN.md.
 */
import iconReversedUrl from '@/assets/icon-reversed.svg';
import iconUrl from '@/assets/icon.svg';
import logoReversedUrl from '@/assets/logo-reversed.svg';
import logoUrl from '@/assets/logo.svg';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        /**
         * Height of the mark in px. The lockup's width follows from it.
         *
         * **40 is the floor for chrome.** The masthead, the operator rail, the
         * auth column and onboarding all pass 40 explicitly, and the default is
         * 40 so a surface that forgets gets the floor rather than a favicon.
         * Below 40 the mark stops reading as the way home and starts reading as
         * a bullet beside the nav; the only things under it are the gallery's
         * own header and the `/dev/components` specimens, neither of which is a
         * product surface.
         */
        size?: number;
        /**
         * `lockup` is the mark and the wordmark; `mark` is the mark alone, for
         * the 56px rail and anywhere else only a square fits.
         */
        variant?: 'mark' | 'lockup';
        /** The dark colourway. Set it from the background, not by taste. */
        reversed?: boolean;
        /**
         * Accessible name. Defaults to the product's name. Set to '' when the
         * logo sits inside a link that is already labelled.
         */
        label?: string;
    }>(),
    { size: 40, variant: 'lockup', reversed: false },
);

const name = computed(() => (usePage().props.appName as string) ?? '');

/** `label` is optional, so `undefined` means "use the name" and '' means "none". */
const altText = computed(() => (props.label === undefined ? name.value : props.label));

const src = computed(() => {
    if (props.variant === 'mark') return props.reversed ? iconReversedUrl : iconUrl;

    return props.reversed ? logoReversedUrl : logoUrl;
});

/*
 * Width, given from the artwork's own viewBox rather than guessed: the lockup
 * is 260x64 and the mark is square. Both attributes are set so the box is
 * reserved before the file arrives — an `<img>` with only a height reflows the
 * rail and the auth column on load, which is the one place a logo is allowed to
 * be the thing that moves.
 */
const width = computed(() => Math.round(props.size * (props.variant === 'mark' ? 1 : 260 / 64)));
</script>

<template>
    <img
        :src="src"
        :alt="altText"
        :width="width"
        :height="size"
        :style="{ width: `${width}px`, height: `${size}px` }"
        class="block shrink-0"
        decoding="async"
    />
</template>
