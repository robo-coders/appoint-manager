<script setup lang="ts">
import { nextTick, ref } from 'vue';
const model = defineModel<string>({ required: true });

const props = defineProps<{
    tabs: Array<{ value: string; label: string; count?: number }>;
    /** Accessible name for the tablist. */
    label?: string;
}>();

const root = ref<HTMLElement | null>(null);

const select = async (value: string, moveFocus = false) => {
    model.value = value;
    if (!moveFocus) return;
    await nextTick();
    root.value?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus();
};

const onKeydown = (event: KeyboardEvent) => {
    const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
    if (!keys.includes(event.key)) return;

    event.preventDefault();
    const values = props.tabs.map((tab) => tab.value);
    const index = values.indexOf(model.value);

    if (event.key === 'Home') return select(values[0], true);
    if (event.key === 'End') return select(values[values.length - 1], true);

    const next = event.key === 'ArrowRight' ? index + 1 : index - 1;

    return select(values[(next + values.length) % values.length], true);
};
</script>

<template>
    <div
        ref="root"
        role="tablist"
        :aria-label="label"
        class="flex items-center gap-1 border-b border-b-rule"
        @keydown="onKeydown"
    >
        <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            role="tab"
            :aria-selected="model === tab.value"
            :tabindex="model === tab.value ? 0 : -1"
            class="-mb-px inline-flex h-control items-center gap-2 border-b-2 px-3 text-13 transition duration-fast ease-product"
            :class="model === tab.value ? 'border-b-ink text-ink' : 'border-b-transparent text-ink-2 hover:text-ink'"
            @click="select(tab.value)"
        >
            {{ tab.label }}
            <span v-if="tab.count !== undefined" class="numeral text-12 text-ink-2">{{ tab.count }}</span>
        </button>
    </div>
</template>
