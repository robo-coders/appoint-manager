<script setup lang="ts">
import Label from './Label.vue';

/**
 * A single figure. Mono at 34px with an 11px mono caption above and the delta
 * beneath — the instrument reading, not a card with a number in it.
 */
withDefaults(
    defineProps<{ label: string; value: string; hint?: string; emphasis?: boolean }>(),
    { emphasis: false },
);
</script>

<template>
    <div class="border-l pl-3" :class="emphasis ? 'border-ink' : 'border-rule'">
        <Label>{{ label }}</Label>
        <p class="numeral mt-1 text-34 text-ink">{{ value }}</p>
        <p v-if="hint" class="mt-0.5 text-12 text-ink-2">{{ hint }}</p>
    </div>
</template>
