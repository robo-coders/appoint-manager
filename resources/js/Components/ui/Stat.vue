<script setup lang="ts">
import Label from './Label.vue';

withDefaults(
    defineProps<{ label: string; value: string; hint?: string; emphasis?: boolean }>(),
    { emphasis: false },
);
</script>

<template>
    <div class="border-l pl-3" :class="emphasis ? 'border-l-ink' : 'border-l-rule'">
        <Label>{{ label }}</Label>
        <p class="numeral mt-1 text-34 text-ink">{{ value }}</p>
        <p v-if="hint" class="mt-0.5 text-12 text-ink-2">{{ hint }}</p>
    </div>
</template>
