<script setup lang="ts">
import { computed } from 'vue';
import Spinner from './Spinner.vue';

const props = withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary' | 'ghost' | 'brand' | 'accent' | 'danger';
        disabled?: boolean;
        loading?: boolean;
        href?: string;
        /** Fills its container. Used on the public booking page. */
        block?: boolean;
    }>(),
    { type: 'button', variant: 'primary', disabled: false, loading: false, block: false },
);

const classes = computed(() => {
    const base =
        'relative inline-flex h-control items-center justify-center gap-2 rounded px-4 text-field font-medium transition duration-fast ease-product disabled:pointer-events-none';

    const variants = {
        // The accent lives here. At most one primary button per screen.
        primary: 'bg-ink text-white hover:opacity-90',
        /*
         * `primary`, repainted in the tenant's colour. The public booking page
         * only — the operator app stays monochrome whatever a salon chooses.
         *
         * --brand defaults to ink in tokens.css, so a salon that has picked no
         * colour gets a button identical to `primary` rather than an invisible
         * one. That default is why this is a separate variant instead of a
         * prop on `primary`: the two are the same button until a tenant says
         * otherwise, and only one page is allowed to ask.
         */
        brand: 'bg-brand text-brand-fg hover:opacity-90',
        /*
         * The one action on a screen that has earned the accent: a cancelled
         * slot that can still be sold. Outlined, not filled — a filled accent
         * button would outrank the primary, and the row it sits in is already
         * carrying a 2px accent border. DESIGN.md rations the accent to one
         * *meaning* per screen; a border, a label and this button on the same
         * row are one use of it, not three.
         */
        accent: 'border border-accent text-accent hover:bg-accent-tint',
        secondary: 'border border-rule bg-paper-sunk text-ink hover:border-rule-strong',
        ghost: 'text-ink-2 hover:bg-paper-sunk hover:text-ink',
        danger: 'border border-rule bg-paper-sunk text-danger hover:border-danger',
    };

    // Loading keeps full contrast — it is working, not unavailable. Only a
    // genuinely disabled control fades.
    const state = props.disabled && !props.loading ? 'opacity-40' : '';

    return [base, variants[props.variant], state, props.block ? 'w-full' : ''].join(' ');
});
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        :href="href"
        :type="href ? undefined : type"
        :disabled="href ? undefined : disabled || loading"
        :aria-disabled="disabled ? 'true' : undefined"
        :aria-busy="loading ? 'true' : undefined"
        :class="classes"
    >
        <!-- The label stays in flow while loading so the button never changes width. -->
        <span :class="loading ? 'invisible' : 'contents'"><slot /></span>
        <span v-if="loading" class="absolute inset-0 flex items-center justify-center">
            <Spinner />
        </span>
    </component>
</template>
