<script setup lang="ts">
defineProps<{
    context: string;
    dayLabel: string;
    time: string;
    costLine: string;
    level?: 'h1' | 'h2';
}>();
</script>

<template>
    <div>
        <p class="caption">{{ context }}</p>

        <component :is="level ?? 'h1'" class="mt-3 text-34 font-medium text-balance">
            {{ dayLabel }}<br />
            at <span class="font-mono">{{ time }}</span>
        </component>

        <p class="mt-3 text-15 text-ink-2">{{ costLine }}</p>
    </div>
</template>
