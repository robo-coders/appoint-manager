<script setup lang="ts">
/**
 * The caption above a control: 13px, ink-2, sentence case, normal tracking.
 *
 * The required marker is ink, not accent. A form with six required fields would
 * otherwise spend the one-per-screen accent six times before anything happened.
 */
defineProps<{ for?: string; required?: boolean }>();
</script>

<template>
    <label :for="$props.for" class="caption block">
        <slot />
        <span v-if="required" class="text-ink" aria-hidden="true">*</span>
    </label>
</template>
