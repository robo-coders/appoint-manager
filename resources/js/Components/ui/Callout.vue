<script setup lang="ts">
/**
 * An inline prompt where the thing it is about lives — "Stripe isn't connected"
 * on the payments screen, not a banner following her around the app.
 */
withDefaults(defineProps<{ tone?: 'neutral' | 'accent' | 'danger'; title?: string }>(), { tone: 'neutral' });
</script>

<template>
    <div
        class="rounded border px-3 py-2.5 text-13"
        :class="{
            'border-rule bg-paper-sunk': tone === 'neutral',
            'border-ink bg-ink-tint': tone === 'accent',
            'border-danger bg-paper-sunk': tone === 'danger',
        }"
    >
        <p v-if="title" class="mb-0.5 text-ink">{{ title }}</p>
        <div class="text-ink-2"><slot /></div>
        <div v-if="$slots.action" class="mt-2"><slot name="action" /></div>
    </div>
</template>
