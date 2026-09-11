<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue';
import Field from './Field.vue';

const model = defineModel<string | number>({ default: '' });

const props = withDefaults(
    defineProps<{
        id?: string;
        label: string;
        labelHidden?: boolean;
        type?: string;
        error?: string;
        hint?: string;
        placeholder?: string;
        disabled?: boolean;
        readonly?: boolean;
        autocomplete?: string;
        required?: boolean;
        autofocus?: boolean;
        mono?: boolean;
        prefix?: string;
        suffix?: string;
        truncate?: boolean;
    }>(),
    {
        type: 'text',
        disabled: false,
        readonly: false,
        required: false,
        autofocus: false,
        mono: false,
        labelHidden: false,
        truncate: false,
    },
);

const emit = defineEmits<{ blur: [FocusEvent] }>();

const uid = useId();
const inputId = computed(() => props.id ?? uid);
const el = ref<HTMLInputElement | null>(null);

const isMono = computed(
    () => props.mono || ['number', 'date', 'time', 'datetime-local', 'tel'].includes(props.type),
);

onMounted(() => props.autofocus && el.value?.focus());

defineExpose({
    focus: () => el.value?.focus(),
    select: () => {
        el.value?.focus();
        el.value?.select();
    },
});
</script>

<template>
    <Field :input-id="inputId" :label="label" :label-hidden="labelHidden" :error="error" :hint="hint" :required="required">
        <template v-if="$slots.error" #error><slot name="error" /></template>
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
                class="h-control w-full rounded border bg-white px-pad-x text-field text-ink transition duration-fast ease-product disabled:cursor-not-allowed disabled:text-ink-2"
                :class="[
                    error ? 'border-danger' : 'border-rule hover:border-rule-strong',
                    isMono ? 'font-mono' : '',
                    prefix ? 'pl-8' : '',
                    suffix ? 'pr-12' : '',
                    truncate ? 'truncate' : '',
                ]"
                @blur="emit('blur', $event)"
            />
            <span v-if="suffix" class="pointer-events-none absolute right-pad-x text-12 text-ink-2">{{ suffix }}</span>
        </div>
    </Field>
</template>
