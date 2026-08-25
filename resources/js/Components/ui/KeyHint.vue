<script setup lang="ts">
/** A keyboard shortcut, shown in passing so the app teaches its own shortcuts. */
defineProps<{ keys: string[] }>();
</script>

<template>
    <span class="inline-flex items-center gap-1">
        <kbd
            v-for="key in keys"
            :key="key"
            class="inline-flex min-w-[1.25rem] items-center justify-center rounded border border-rule bg-paper-sunk px-1 py-0.5 text-12 text-ink-2"
            >{{ key }}</kbd
        >
    </span>
</template>
