<script setup lang="ts">
/**
 * Status at a glance.
 *
 * The admin app is monochrome: status reads from ink weight, not hue. Only a
 * cancellation earns colour. Meaning is never carried by colour alone — the
 * label is always present, and the dot is decoration on top of it.
 *
 * Height is `--badge-h` (20px) rather than vertical padding, so a badge is the
 * same height whatever is inside it and a row of them sits on one baseline.
 *
 * The `accent` tone puts accent *type* on white, not on `--accent-tint`. Accent
 * on accent-tint measures 4.50:1 — exactly on the threshold, which is not a
 * margin, it is a coin toss.
 */
withDefaults(
    defineProps<{ tone?: 'confirmed' | 'pending' | 'cancelled' | 'neutral' | 'accent' }>(),
    { tone: 'neutral' },
);
</script>

<template>
    <span
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
