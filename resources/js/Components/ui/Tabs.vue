<script setup lang="ts">
import { nextTick, ref } from 'vue';
const model = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        tabs: Array<{ value: string; label: string; count?: number }>;
        label?: string;
        variant?: 'underline' | 'filter';
    }>(),
    { variant: 'underline' },
);

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
        class="border-b border-b-rule"
        :class="variant === 'filter' ? 'flex flex-wrap items-center gap-x-4 gap-y-3 pb-3' : ''"
    >
        <div
            ref="root"
            role="tablist"
            :aria-label="label"
            class="flex items-center gap-1 overflow-x-auto"
            :class="variant === 'filter' ? 'min-w-0 flex-1' : ''"
            @keydown="onKeydown"
        >
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                role="tab"
                :aria-selected="model === tab.value"
                :tabindex="model === tab.value ? 0 : -1"
                class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-13 transition duration-fast ease-product"
                :class="[
                    variant === 'filter'
                        ? 'rounded px-3 py-1 hover:bg-ink-tint'
                        : '-mb-px h-control border-b-2 px-3',
                    model === tab.value
                        ? variant === 'filter'
                            ? 'bg-pill-neutral font-medium text-ink'
                            : 'border-b-ink text-ink'
                        : variant === 'filter'
                          ? 'text-ink-2 hover:text-ink'
                          : 'border-b-transparent text-ink-2 hover:text-ink',
                ]"
                @click="select(tab.value)"
            >
                {{ tab.label }}
                <span v-if="tab.count !== undefined" class="numeral text-12 text-ink-2">{{ tab.count }}</span>
            </button>
        </div>

        <div v-if="variant === 'filter' && $slots.end" class="flex min-w-0 max-w-full items-center gap-2">
            <slot name="end" />
        </div>
    </div>
</template>
