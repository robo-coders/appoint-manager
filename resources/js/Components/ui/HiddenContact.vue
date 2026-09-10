<script setup lang="ts">
/**
 * What stands in for a contact detail somebody is not allowed to read.
 *
 * **Not a blank, and not an em dash.** Both of those already mean something on
 * these screens: "this customer has no phone number on file". Reusing them for
 * "there is a number and you may not see it" would send somebody off to fix a
 * record that is not broken — and, worse, would make a withheld number look
 * identical to a missing one on the exact screen whose job is ringing people.
 *
 * So the masked value is shown where there is one — `MaskedContact::phone`
 * keeps the last two digits, which is enough to check you have the right person
 * when an owner reads a number out and nowhere near enough to dial — and the
 * reason is available as a title on hover and to a screen reader.
 *
 * The value here is already masked by the time it arrives: the server sends the
 * bullets, not the number. See `App\Support\ContactVisibility`.
 */
withDefaults(
    defineProps<{
        /**
         * The masked value, e.g. `••••••23`.
         *
         * Omit it for a detail that has no partial form — an email, whose
         * useful half is the half in front of the `@`, so there is no version
         * of a partial one that is neither a leak nor useless. A fixed run of
         * bullets stands in.
         *
         * **Callers must only render this component when a value exists.** A
         * customer who genuinely gave no number gets `ui/PhoneLink`'s em dash;
         * bullets here would claim a number is on file when none is.
         */
        masked?: string | null;
        /** Long form, for a record page with room for a sentence. */
        verbose?: boolean;
    }>(),
    { masked: null, verbose: false },
);

const NOTICE = 'Contact hidden — ask an owner';

/*
 * Never an em dash. That is what `ui/PhoneLink` renders for a customer who has
 * no number, and "withheld" reading identically to "absent" is the one failure
 * this component exists to prevent — it would send somebody off to fix a record
 * that is not broken, on the screen whose whole job is ringing people.
 */
const PLACEHOLDER = '••••••';
</script>

<template>
    <span v-if="verbose" class="text-ink-2">
        <span class="numeral">{{ masked ?? PLACEHOLDER }}</span> · {{ NOTICE }}
    </span>

    <span v-else class="inline-flex items-center gap-1 text-ink-2" :title="NOTICE">
        <span class="numeral">{{ masked ?? PLACEHOLDER }}</span>
        <span class="sr-only">{{ NOTICE }}</span>
    </span>
</template>
