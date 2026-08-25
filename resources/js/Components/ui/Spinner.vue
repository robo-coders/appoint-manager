<script setup lang="ts">
withDefaults(defineProps<{ size?: number; label?: string }>(), { size: 14, label: 'Loading' });
</script>

<template>
    <svg
        :width="size"
        :height="size"
        viewBox="0 0 16 16"
        fill="none"
        role="status"
        :aria-label="label"
        class="animate-spin"
    >
        <circle cx="8" cy="8" r="6.5" stroke="currentColor" stroke-opacity="0.25" stroke-width="2" />
        <path d="M14.5 8A6.5 6.5 0 0 0 8 1.5" stroke="currentColor" stroke-width="2" stroke-linecap="butt" />
    </svg>
</template>
