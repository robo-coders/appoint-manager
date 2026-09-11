<script setup lang="ts">
import { computed, ref, useId } from 'vue';
import FieldError from './FieldError.vue';

const model = defineModel<string>({ required: true });

const props = defineProps<{
    legend: string;
    options: Array<{ value: string; label: string; hint?: string; sample?: string }>;
    name?: string;
    error?: string;
    disabled?: boolean;
    legendHidden?: boolean;
    required?: boolean;
}>();

const uid = useId();
const groupName = computed(() => props.name ?? `radio-${uid}`);

const el = ref<HTMLFieldSetElement | null>(null);

defineExpose({
    focus: () => {
        const group = el.value;

        if (!group) return;

        const target =
            group.querySelector<HTMLInputElement>('input[type="radio"]:checked') ??
            group.querySelector<HTMLInputElement>('input[type="radio"]');

        target?.focus();
    },
});
</script>

<template>
    <fieldset ref="el" class="space-y-3" :aria-describedby="error ? `${uid}-error` : undefined">
        <!-- prettier-ignore -->
        <legend class="caption" :class="legendHidden ? 'sr-only' : ''">{{ legend }}<span v-if="required" class="text-ink" aria-hidden="true">*</span></legend>

        <label
            v-for="option in options"
            :key="option.value"
            class="flex min-h-row items-start gap-3 border-b border-b-rule py-3"
            :class="disabled ? 'cursor-not-allowed' : 'cursor-pointer'"
        >
            <input
                v-model="model"
                type="radio"
                :name="groupName"
                :value="option.value"
                :disabled="disabled"
                :aria-invalid="error ? 'true' : undefined"
                class="mt-0.5 size-4 shrink-0 border-rule-strong bg-paper-sunk text-ink disabled:cursor-not-allowed"
            />
            <span class="min-w-0">
                <span class="block text-13" :class="disabled ? 'text-ink-2' : 'text-ink'">{{ option.label }}</span>
                <span v-if="option.hint" class="mt-0.5 block text-12 text-ink-2">{{ option.hint }}</span>
                <span v-if="option.sample" class="mt-2 block font-mono text-12 text-ink-2">{{ option.sample }}</span>
            </span>
        </label>

        <FieldError :id="`${uid}-error`" :message="error" />
        <slot />
    </fieldset>
</template>
