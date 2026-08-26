<script setup lang="ts">
import { computed, useId } from 'vue';

const model = defineModel<boolean>({ default: false });

const props = defineProps<{ id?: string; label: string; hint?: string; disabled?: boolean }>();
const uid = useId();
const inputId = computed(() => props.id ?? uid);
</script>

<template>
    <div class="flex items-start gap-2">
        <input
            :id="inputId"
            v-model="model"
            type="checkbox"
            :disabled="disabled"
            class="mt-0.5 size-4 shrink-0 rounded-none border-rule-strong bg-paper-sunk text-ink disabled:cursor-not-allowed"
        />
        <label :for="inputId" class="select-none text-13 text-ink" :class="disabled ? 'text-ink-2' : ''">
            {{ label }}
            <span v-if="hint" class="mt-0.5 block text-12 text-ink-2">{{ hint }}</span>
        </label>
    </div>
</template>
