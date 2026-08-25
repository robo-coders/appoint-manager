<script setup lang="ts">
/**
 * Shaped like the content it replaces, never a generic bar and never a spinner.
 * Callers pass a shape, not a size.
 */
withDefaults(
    defineProps<{
        shape?: 'text' | 'heading' | 'block' | 'row' | 'card' | 'stat';
        lines?: number;
        /** Tailwind width class for the last line, so text looks ragged not blocked. */
        width?: string;
    }>(),
    { shape: 'text', lines: 3, width: 'w-full' },
);
</script>

<template>
    <div role="status" aria-label="Loading" class="animate-pulse">
        <template v-if="shape === 'text'">
            <div class="space-y-2">
                <div
                    v-for="line in lines"
                    :key="line"
                    class="h-3 rounded bg-paper-sunk"
                    :class="line === lines ? 'w-2/3' : width"
                />
            </div>
        </template>

        <div v-else-if="shape === 'heading'" class="h-6 w-48 rounded bg-paper-sunk" />

        <div v-else-if="shape === 'block'" class="h-32 rounded bg-paper-sunk" :class="width" />

        <template v-else-if="shape === 'row'">
            <div v-for="line in lines" :key="line" class="flex h-row items-center gap-4 border-b border-rule">
                <div class="h-3 w-1/4 rounded bg-paper-sunk" />
                <div class="h-3 w-1/3 rounded bg-paper-sunk" />
                <div class="ml-auto h-3 w-16 rounded bg-paper-sunk" />
            </div>
        </template>

        <div v-else-if="shape === 'card'" class="space-y-3 rounded border border-rule bg-white p-4">
            <div class="h-3 w-24 rounded bg-paper-sunk" />
            <div class="h-6 w-40 rounded bg-paper-sunk" />
            <div class="h-3 w-full rounded bg-paper-sunk" />
        </div>

        <div v-else class="space-y-2 rounded border border-rule bg-white p-4">
            <div class="h-2.5 w-20 rounded bg-paper-sunk" />
            <div class="h-8 w-24 rounded bg-paper-sunk" />
        </div>
    </div>
</template>
