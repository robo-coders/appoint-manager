<script setup lang="ts">
import Label from './Label.vue';

/**
 * A single figure: a 13px caption, the number in mono at 34px, and an optional
 * hint beneath. The instrument reading, not a card with a number in it.
 *
 * The rule is on the left only, so its colour is scoped to the left only.
 * `border-ink` would paint all four sides and the emphasised variant would
 * become a box.
 */
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
