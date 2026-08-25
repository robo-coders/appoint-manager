<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ minutes: number; short?: boolean }>();

const text = computed(() => {
    const h = Math.floor(props.minutes / 60);
    const m = props.minutes % 60;
    if (h === 0) return `${m}${props.short ? 'm' : ' min'}`;
    if (m === 0) return `${h}${props.short ? 'h' : ' hr'}`;
    return props.short ? `${h}h ${m}m` : `${h} hr ${m} min`;
});
</script>

<template>
    <span class="numeral whitespace-nowrap">{{ text }}</span>
</template>
