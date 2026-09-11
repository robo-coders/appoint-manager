<script setup lang="ts">
import Label from './Label.vue';
import FieldError from './FieldError.vue';

defineProps<{
    inputId: string;
    label: string;
    error?: string;
    hint?: string;
    required?: boolean;
    labelHidden?: boolean;
}>();
</script>

<template>
    <div class="space-y-1">
        <Label :for="inputId" :required="required" :class="labelHidden ? 'sr-only' : ''">{{ label }}</Label>
        <slot />
        <p v-if="hint && !error" class="text-12 text-ink-2">{{ hint }}</p>
        <FieldError v-if="$slots.error" :id="`${inputId}-error`" :message="error">
            <slot name="error" />
        </FieldError>
        <FieldError v-else :id="`${inputId}-error`" :message="error" />
    </div>
</template>
