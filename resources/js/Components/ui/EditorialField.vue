<script setup lang="ts">
import { computed, onMounted, ref, useId } from 'vue';

const model = defineModel<string>({ default: '' });

const props = withDefaults(
    defineProps<{
        label: string;
        type?: string;
        error?: string;
        placeholder?: string;
        autocomplete?: string;
        required?: boolean;
        autofocus?: boolean;
        reveal?: boolean;
    }>(),
    { type: 'text', required: false, autofocus: false, reveal: false },
);

const uid = useId();
const el = ref<HTMLInputElement | null>(null);
const shown = ref(false);

const type = computed(() => (props.reveal && shown.value ? 'text' : props.type));

onMounted(() => props.autofocus && el.value?.focus());
defineExpose({ focus: () => el.value?.focus() });
</script>

<template>
    <div class="ed-field">
        <label class="ed-field-label" :for="uid">
            <span>{{ label }}</span>
            <slot name="action" />
        </label>

        <span class="ed-field-control">
            <input
                :id="uid"
                ref="el"
                v-model="model"
                :type="type"
                :placeholder="placeholder"
                :autocomplete="autocomplete"
                :required="required"
                :aria-invalid="error ? 'true' : undefined"
                :aria-describedby="error ? `${uid}-error` : undefined"
                class="ed-input"
                :class="[error ? 'ed-input--error' : '', reveal ? 'ed-input--revealable' : '']"
            />
            <button
                v-if="reveal"
                type="button"
                class="ed-reveal"
                :aria-label="shown ? 'Hide password' : 'Show password'"
                @click="shown = !shown"
            >
                {{ shown ? 'HIDE' : 'SHOW' }}
            </button>
        </span>

        <span v-if="error" :id="`${uid}-error`" class="ed-field-error">{{ error }}</span>
    </div>
</template>
