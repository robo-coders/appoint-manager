<script setup lang="ts">
withDefaults(
    defineProps<{
        tone?: 'confirmed' | 'pending' | 'cancelled' | 'neutral' | 'accent';
        variant?: 'outline' | 'solid';
    }>(),
    { tone: 'neutral', variant: 'outline' },
);
</script>

<template>
    <span
        v-if="variant === 'solid'"
        class="inline-flex h-badge items-center whitespace-nowrap rounded px-2 text-12 font-medium"
        :class="{
            'bg-pill-neutral text-ink': tone === 'confirmed' || tone === 'pending',
            'bg-pill-accent text-accent-strong': tone === 'accent',
            'bg-pill-muted text-ink-2': tone === 'cancelled' || tone === 'neutral',
        }"
    >
        <slot />
    </span>

    <span
        v-else
        class="inline-flex h-badge items-center gap-1 whitespace-nowrap rounded border px-2 text-12"
        :class="{
            'border-rule bg-white text-ink': tone === 'confirmed',
            'border-rule bg-paper-sunk text-ink-2': tone === 'pending' || tone === 'neutral',
            'border-danger bg-white text-danger': tone === 'cancelled',
            'border-accent bg-white text-accent': tone === 'accent',
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
