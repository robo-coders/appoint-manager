<script setup lang="ts">
/** The 11px mono instrument label. See DESIGN.md, "the three distinctive moves". */
defineProps<{ for?: string; required?: boolean }>();
</script>

<template>
    <label :for="$props.for" class="caption block">
        <slot />
        <span v-if="required" class="text-accent" aria-hidden="true">*</span>
    </label>
</template>
