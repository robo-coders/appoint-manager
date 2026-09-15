<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        value: string;
        hint?: string;
        emphasis?: boolean;
        /*
         * The hint is muted by default and accent only when it is reporting
         * something that wants doing — "needs review", not "+6 this week". A
         * strip where every card carries a coloured figure has spent the
         * screen's one accent four times and says nothing with it.
         */
        hintTone?: 'muted' | 'accent';
    }>(),
    { emphasis: false, hintTone: 'muted' },
);
</script>

<template>
    <div class="border-l pl-3" :class="emphasis ? 'border-l-ink' : 'border-l-rule'">
        <p class="eyebrow">{{ label }}</p>
        <p class="numeral mt-1 text-34 text-ink">{{ value }}</p>
        <p v-if="hint" class="mt-0.5 text-12" :class="hintTone === 'accent' ? 'text-accent' : 'text-ink-2'">
            {{ hint }}
        </p>
    </div>
</template>
