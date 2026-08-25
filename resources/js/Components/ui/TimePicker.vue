<script setup lang="ts">
import TextInput from './TextInput.vue';

const model = defineModel<string>({ default: '' });
defineProps<{ label: string; error?: string; hint?: string; disabled?: boolean; required?: boolean }>();
</script>

<template>
    <TextInput
        v-model="model"
        type="time"
        :label="label"
        :error="error"
        :hint="hint"
        :disabled="disabled"
        :required="required"
    />
</template>
