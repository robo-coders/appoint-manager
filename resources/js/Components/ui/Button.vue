<script setup lang="ts">
import { computed } from 'vue';
import Spinner from './Spinner.vue';

const props = withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'primary' | 'secondary' | 'ghost' | 'brand' | 'accent' | 'accent-solid' | 'danger';
        disabled?: boolean;
        loading?: boolean;
        href?: string;
        block?: boolean;
    }>(),
    { type: 'button', variant: 'primary', disabled: false, loading: false, block: false },
);

const classes = computed(() => {
    const base =
        'relative inline-flex h-control items-center justify-center gap-2 rounded px-4 text-field font-medium transition duration-fast ease-product disabled:pointer-events-none';

    const variants = {
        primary: 'bg-ink text-white hover:opacity-90',
        brand: 'bg-brand text-brand-fg hover:opacity-90',
        accent: 'border border-accent text-accent hover:bg-accent-tint',
        'accent-solid': 'bg-accent text-paper hover:bg-accent-strong',
        secondary: 'border border-rule bg-paper-sunk text-ink hover:border-rule-strong',
        ghost: 'text-ink-2 hover:bg-paper-sunk hover:text-ink',
        danger: 'border border-rule bg-paper-sunk text-danger hover:border-danger',
    };

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
        <span :class="loading ? 'invisible' : 'contents'"><slot /></span>
        <span v-if="loading" class="absolute inset-0 flex items-center justify-center">
            <Spinner />
        </span>
    </component>
</template>
