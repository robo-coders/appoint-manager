<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue';
import Field from './Field.vue';

const model = defineModel<string | number>({ default: '' });

const props = withDefaults(
    defineProps<{
        id?: string;
        label: string;
        /** Visually hidden, still announced. See `ui/Field`. */
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
        /** Numbers, times, prices and IDs are always mono tabular. */
        mono?: boolean;
        /** A short unit or symbol rendered inside the field, e.g. £ or min. */
        prefix?: string;
        suffix?: string;
        /**
         * Clips an over-long value with an ellipsis instead of letting it
         * scroll. For a readonly field holding a value nobody types — a feed
         * URL, an API key — where the row has no room for the whole string.
         * Display only: the element still holds the full value, so select-all
         * and copy return every character of it.
         */
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

/*
 * `blur` is declared rather than left to attribute fallthrough, and that is not
 * a preference. Fallthrough lands `onBlur` on this component's root — the
 * `ui/Field` wrapper `<div>` — and `blur` does not bubble, so a page writing
 * `@blur` on a `TextInput` got a handler that never fired once. `/register`
 * validates each field as it is left, so it needs the real event.
 */
const emit = defineEmits<{ blur: [FocusEvent] }>();

const uid = useId();
const inputId = computed(() => props.id ?? uid);
const el = ref<HTMLInputElement | null>(null);

// Types that are always numeric get mono treatment without being asked.
const isMono = computed(
    () => props.mono || ['number', 'date', 'time', 'datetime-local', 'tel'].includes(props.type),
);

onMounted(() => props.autofocus && el.value?.focus());

defineExpose({
    focus: () => el.value?.focus(),
    /*
     * The recovery when the clipboard is unavailable: put the value under the
     * caret so the reader can press the shortcut themselves. A page cannot do
     * this from outside — the `<input>` lives in here.
     */
    select: () => {
        el.value?.focus();
        el.value?.select();
    },
});
</script>

<template>
    <Field :input-id="inputId" :label="label" :label-hidden="labelHidden" :error="error" :hint="hint" :required="required">
        <!-- Only forwarded when the page provides it; see the note in ui/Field. -->
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
