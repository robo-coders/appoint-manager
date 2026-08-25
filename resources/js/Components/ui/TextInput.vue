<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue';
import Field from './Field.vue';

const model = defineModel<string | number>({ default: '' });

const props = withDefaults(
    defineProps<{
        id?: string;
        label: string;
        type?: string;
        error?: string;
        hint?: string;
        placeholder?: string;
        disabled?: boolean;
        readonly?: boolean;
        autocomplete?: string;
        required?: boolean;
        autofocus?: boolean;
        /** Numbers, times, prices and IDs are always mono tabular. */
        mono?: boolean;
        /** A short unit or symbol rendered inside the field, e.g. £ or min. */
        prefix?: string;
        suffix?: string;
    }>(),
    { type: 'text', disabled: false, readonly: false, required: false, autofocus: false, mono: false },
);

const uid = useId();
const inputId = computed(() => props.id ?? uid);
const el = ref<HTMLInputElement | null>(null);

// Types that are always numeric get mono treatment without being asked.
const isMono = computed(
    () => props.mono || ['number', 'date', 'time', 'datetime-local', 'tel'].includes(props.type),
);

onMounted(() => props.autofocus && el.value?.focus());
defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <Field :input-id="inputId" :label="label" :error="error" :hint="hint" :required="required">
        <div class="relative flex items-center">
            <span v-if="prefix" class="pointer-events-none absolute left-pad-x text-field text-ink-2">{{ prefix }}</span>
            <input
                :id="inputId"
                ref="el"
                v-model="model"
                :type="type"
                :disabled="disabled"
                :readonly="readonly"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :required="required"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="error ? `${inputId}-error` : undefined"
                class="h-control w-full rounded border bg-paper-sunk px-pad-x text-field text-ink transition duration-fast ease-product disabled:cursor-not-allowed disabled:text-ink-2"
                :class="[
                    error ? 'border-danger' : 'border-rule hover:border-rule-strong',
                    isMono ? 'font-mono' : '',
                    prefix ? 'pl-8' : '',
                    suffix ? 'pr-12' : '',
                ]"
            />
            <span v-if="suffix" class="pointer-events-none absolute right-pad-x text-12 text-ink-2">{{ suffix }}</span>
        </div>
    </Field>
</template>
