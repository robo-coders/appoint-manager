<script setup lang="ts">
import { computed, nextTick, ref, useId } from 'vue';
import Field from './Field.vue';

/**
 * A select you can type into. Exists because a 400-option timezone dropdown is
 * not a control, it is a punishment.
 */
const model = defineModel<string>({ default: '' });

const props = defineProps<{
    id?: string;
    label: string;
    options: Array<{ value: string; label: string }>;
    error?: string;
    hint?: string;
    placeholder?: string;
    disabled?: boolean;
    required?: boolean;
}>();

const uid = useId();
const inputId = computed(() => props.id ?? uid);
const open = ref(false);
const query = ref('');
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);

const selectedLabel = computed(() => props.options.find((o) => o.value === model.value)?.label ?? '');

const matches = computed(() => {
    const q = query.value.trim().toLowerCase();
    const list = q === '' ? props.options : props.options.filter((o) => o.label.toLowerCase().includes(q));
    return list.slice(0, 50);
});

const openList = async () => {
    if (props.disabled) return;
    open.value = true;
    query.value = '';
    active.value = Math.max(0, matches.value.findIndex((o) => o.value === model.value));
    await nextTick();
    input.value?.focus();
};

const choose = (value: string) => {
    model.value = value;
    open.value = false;
};

const onKey = (event: KeyboardEvent) => {
    if (event.key === 'Escape') return void (open.value = false);
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(active.value + 1, matches.value.length - 1);
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    }
    if (event.key === 'Enter' && matches.value[active.value]) {
        event.preventDefault();
        choose(matches.value[active.value].value);
    }
};
</script>

<template>
    <Field :input-id="inputId" :label="label" :error="error" :hint="hint" :required="required">
        <div class="relative">
            <button
                v-if="!open"
                :id="inputId"
                type="button"
                :disabled="disabled"
                :aria-describedby="error ? `${inputId}-error` : undefined"
                class="h-control w-full truncate rounded border bg-paper-sunk px-pad-x text-left text-field text-ink transition duration-fast ease-product disabled:cursor-not-allowed disabled:text-ink-2"
                :class="error ? 'border-danger' : 'border-rule hover:border-rule-strong'"
                @click="openList"
            >
                <span :class="selectedLabel ? '' : 'text-ink-2'">{{ selectedLabel || placeholder || 'Choose…' }}</span>
            </button>

            <template v-else>
                <input
                    ref="input"
                    v-model="query"
                    type="text"
                    role="combobox"
                    aria-expanded="true"
                    :aria-controls="`${inputId}-list`"
                    :placeholder="placeholder ?? 'Type to filter…'"
                    class="h-control w-full rounded border border-ink bg-paper-sunk px-pad-x text-field text-ink"
                    @keydown="onKey"
                    @blur="open = false"
                />
                <ul
                    :id="`${inputId}-list`"
                    role="listbox"
                    class="appear absolute z-30 mt-1 max-h-64 w-full overflow-auto rounded border border-rule bg-white py-1"
                >
                    <li
                        v-for="(option, index) in matches"
                        :key="option.value"
                        role="option"
                        :aria-selected="option.value === model"
                        class="cursor-pointer px-pad-x py-1.5 text-13"
                        :class="index === active ? 'bg-ink-tint text-ink' : 'text-ink-2'"
                        @mousedown.prevent="choose(option.value)"
                        @mouseenter="active = index"
                    >
                        {{ option.label }}
                    </li>
                    <li v-if="matches.length === 0" class="px-pad-x py-1.5 text-13 text-ink-2">No matches.</li>
                </ul>
            </template>
        </div>
    </Field>
</template>
