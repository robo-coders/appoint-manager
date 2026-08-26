<script setup lang="ts">
import { computed, useId } from 'vue';
import Field from './Field.vue';

const model = defineModel<string | number>({ default: '' });

const props = defineProps<{
    id?: string;
    label: string;
    error?: string;
    hint?: string;
    disabled?: boolean;
    required?: boolean;
    /** Optional data-driven options; a default slot of <option> also works. */
    options?: Array<{ value: string | number; label: string }>;
}>();

const uid = useId();
const inputId = computed(() => props.id ?? uid);
</script>

<template>
    <Field :input-id="inputId" :label="label" :error="error" :hint="hint" :required="required">
        <select
            :id="inputId"
            v-model="model"
            :disabled="disabled"
            :required="required"
            :aria-invalid="error ? 'true' : undefined"
            :aria-describedby="error ? `${inputId}-error` : undefined"
            class="h-control w-full rounded border bg-white px-pad-x text-field text-ink transition duration-fast ease-product disabled:cursor-not-allowed disabled:text-ink-2"
            :class="error ? 'border-danger' : 'border-rule hover:border-rule-strong'"
        >
            <option v-for="option in options ?? []" :key="option.value" :value="option.value">
                {{ option.label }}
            </option>
            <slot />
        </select>
    </Field>
</template>
