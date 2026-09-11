<script setup lang="ts">
withDefaults(
    defineProps<{
        masked?: string | null;
        verbose?: boolean;
    }>(),
    { masked: null, verbose: false },
);

const NOTICE = 'Contact hidden — ask an owner';

const PLACEHOLDER = '••••••';
</script>

<template>
    <span v-if="verbose" class="text-ink-2">
        <span class="numeral">{{ masked ?? PLACEHOLDER }}</span> · {{ NOTICE }}
    </span>

    <span v-else class="inline-flex items-center gap-1 text-ink-2" :title="NOTICE">
        <span class="numeral">{{ masked ?? PLACEHOLDER }}</span>
        <span class="sr-only">{{ NOTICE }}</span>
    </span>
</template>
