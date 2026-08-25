<script setup lang="ts">
import Label from './Label.vue';
import FieldError from './FieldError.vue';

/**
 * Label + control + hint/error. Every input in the product is wrapped in one of
 * these, so "every input has a visible label" is structural rather than a rule-line
 * people have to remember.
 */
defineProps<{
    inputId: string;
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
}>();
</script>

<template>
    <div class="space-y-1">
        <Label :for="inputId" :required="required">{{ label }}</Label>
        <slot />
        <p v-if="hint && !error" class="text-12 text-ink-2">{{ hint }}</p>
        <FieldError :id="`${inputId}-error`" :message="error" />
    </div>
</template>
