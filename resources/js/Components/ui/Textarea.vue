<script setup lang="ts">
import { computed, useId } from 'vue';
import Field from './Field.vue';

const model = defineModel<string>({ default: '' });

const props = withDefaults(
    defineProps<{
        id?: string;
        label: string;
        rows?: number;
        error?: string;
        hint?: string;
        placeholder?: string;
        disabled?: boolean;
        required?: boolean;
    }>(),
    { rows: 4, disabled: false, required: false },
);

const uid = useId();
const inputId = computed(() => props.id ?? uid);
</script>

<template>
    <Field :input-id="inputId" :label="label" :error="error" :hint="hint" :required="required">
        <textarea
            :id="inputId"
            v-model="model"
            :rows="rows"
            :disabled="disabled"
            :placeholder="placeholder"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${inputId}-error` : undefined"
            class="block w-full rounded border bg-paper-sunk px-pad-x py-2 text-field text-ink transition duration-fast ease-product disabled:cursor-not-allowed disabled:text-ink-2"
            :class="error ? 'border-danger' : 'border-rule hover:border-rule-strong'"
        />
    </Field>
</template>
