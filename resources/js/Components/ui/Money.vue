<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    value: { amount: number; formatted: string; currency: string } | string;
    muted?: boolean;
}>();

const text = computed(() => (typeof props.value === 'string' ? props.value : props.value.formatted));
</script>

<template>
    <span class="numeral whitespace-nowrap" :class="muted ? 'text-ink-2' : ''">{{ text }}</span>
</template>
