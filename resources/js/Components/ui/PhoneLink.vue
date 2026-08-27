<script setup lang="ts">
/**
 * A phone number you can tap.
 *
 * Numbers were plain text everywhere they appeared, which on a phone is the one
 * screen size where that is most obviously wrong: a salon owner looking a
 * customer up between dogs wants to ring them, and the number being text meant
 * reading it off the screen and dialling it by hand.
 *
 * A `tel:` href is the whole fix, and it belongs in the library rather than in
 * each screen because there are two screens showing numbers — Customers and the
 * Waitlist — and there will be more.
 *
 * Mono and tabular, like every other number in this product.
 */
withDefaults(
    defineProps<{
        /** The number as the salon typed it. Null renders an em dash. */
        phone?: string | null;
        /**
         * A 44px tap target below md, collapsing at md and up.
         *
         * On by default: the reason this component exists is the phone. It is
         * turned off inside a wide table row, which is 34px tall — a 44px target
         * in it would make every row in the table taller for a control that a
         * mouse hits without help.
         */
        tap?: boolean;
    }>(),
    { phone: null, tap: true },
);
</script>

<template>
    <a
        v-if="phone"
        :href="`tel:${phone}`"
        class="numeral inline-flex items-center underline decoration-rule-strong underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
        :class="tap ? 'min-h-tap md:min-h-0' : ''"
        >{{ phone }}</a
    >
    <!--
        Never an empty cell. A blank where a number should be reads as a page
        that failed to load the number rather than as a customer who has not
        given one.
    -->
    <span v-else class="numeral text-ink-2">—</span>
</template>
