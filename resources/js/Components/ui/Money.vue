<script setup lang="ts">
import { computed } from 'vue';

/**
 * Integer pence in, mono tabular out. Accepts the Money payload the API sends
 * or a pre-formatted string; never does float arithmetic on money.
 */
const props = defineProps<{
    value: { amount: number; formatted: string; currency: string } | string;
    /** Muted when it is a secondary figure, e.g. "of which £10 deposit". */
    muted?: boolean;
}>();

const text = computed(() => (typeof props.value === 'string' ? props.value : props.value.formatted));
</script>

<template>
    <span class="numeral whitespace-nowrap" :class="muted ? 'text-ink-2' : ''">{{ text }}</span>
</template>
