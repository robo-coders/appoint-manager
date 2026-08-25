<script setup lang="ts">
import { computed, useId } from 'vue';

const model = defineModel<boolean>({ default: false });

const props = defineProps<{ id?: string; label: string; hint?: string; disabled?: boolean }>();
const uid = useId();
const inputId = computed(() => props.id ?? uid);
</script>

<template>
    <div class="flex items-center justify-between gap-4">
        <label :for="inputId" class="text-13 text-ink" :class="disabled ? 'text-ink-2' : ''">
            {{ label }}
            <span v-if="hint" class="mt-0.5 block text-12 text-ink-2">{{ hint }}</span>
        </label>
        <button
            :id="inputId"
            type="button"
            role="switch"
            :aria-checked="model"
            :disabled="disabled"
            class="relative h-6 w-10 shrink-0 rounded border transition duration-fast ease-product disabled:cursor-not-allowed disabled:opacity-50"
            :class="model ? 'border-ink bg-ink' : 'border-rule-strong bg-paper-sunk'"
            @click="model = !model"
        >
            <span
                class="absolute top-0.5 size-4 rounded transition duration-fast ease-product"
                :class="model ? 'left-[1.125rem] bg-white' : 'left-0.5 bg-ink-3'"
            />
        </button>
    </div>
</template>
