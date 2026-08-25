<script setup lang="ts">
/**
 * Status at a glance.
 *
 * The admin app is monochrome: status reads from ink weight, not hue. Only a
 * cancellation earns colour. Meaning is never carried by colour alone — the
 * label is always present, and the dot is decoration on top of it.
 */
withDefaults(
    defineProps<{ tone?: 'confirmed' | 'pending' | 'cancelled' | 'neutral' | 'accent' }>(),
    { tone: 'neutral' },
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1.5 whitespace-nowrap rounded border px-1.5 py-0.5 text-12"
        :class="{
            'border-rule bg-white text-ink': tone === 'confirmed',
            'border-rule bg-paper-sunk text-ink-2': tone === 'pending' || tone === 'neutral',
            'border-rule bg-white text-danger': tone === 'cancelled',
            'border-rule bg-accent-tint text-accent': tone === 'accent',
        }"
    >
        <span
            class="size-1.5 shrink-0 rounded"
            :class="{
                'bg-ink': tone === 'confirmed',
                'bg-ink-3': tone === 'pending' || tone === 'neutral',
                'bg-danger': tone === 'cancelled',
                'bg-accent': tone === 'accent',
            }"
            aria-hidden="true"
        />
        <slot />
    </span>
</template>
