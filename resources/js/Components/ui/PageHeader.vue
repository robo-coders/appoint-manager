<script setup lang="ts">
/**
 * Inline with the content, not in a separate chrome band — the operator app has
 * no vertical space to spare.
 */
defineProps<{ title: string; description?: string }>();
</script>

<template>
    <div class="mb-4 flex flex-wrap items-end justify-between gap-3">
        <div>
            <h1 class="text-20">{{ title }}</h1>
            <p v-if="description" class="mt-0.5 text-13 text-ink-2">{{ description }}</p>
        </div>
        <div class="flex items-center gap-2"><slot /></div>
    </div>
</template>
