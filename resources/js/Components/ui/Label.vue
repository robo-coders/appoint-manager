<script setup lang="ts">
defineProps<{ for?: string; required?: boolean }>();
</script>

<template>
    <label :for="$props.for" class="caption block">
        <slot />
        <span v-if="required" class="text-ink" aria-hidden="true">*</span>
    </label>
</template>
