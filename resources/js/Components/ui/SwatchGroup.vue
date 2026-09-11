<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';

const model = defineModel<string | null>({ required: true });

const props = defineProps<{
    options: string[];
    label: string;
}>();

const root = ref<HTMLElement | null>(null);

const display = (name: string) => name.charAt(0).toUpperCase() + name.slice(1);

const focusedName = computed(() => (model.value && props.options.includes(model.value) ? model.value : props.options[0]));

const select = async (value: string, moveFocus = false) => {
    model.value = value;

    if (!moveFocus) return;

    await nextTick();
    root.value?.querySelector<HTMLElement>('[aria-checked="true"]')?.focus();
};

const onKeydown = (event: KeyboardEvent) => {
    const keys = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'];
    if (!keys.includes(event.key)) return;

    event.preventDefault();

    const { options } = props;
    if (options.length === 0) return;

    if (event.key === 'Home') return select(options[0], true);
    if (event.key === 'End') return select(options[options.length - 1], true);

    const current = model.value === null ? -1 : options.indexOf(model.value);
    const forward = event.key === 'ArrowRight' || event.key === 'ArrowDown';

    if (current === -1) return select(forward ? options[0] : options[options.length - 1], true);

    const next = forward ? current + 1 : current - 1;

    return select(options[(next + options.length) % options.length], true);
};
</script>

<template>
    <div
        ref="root"
        role="radiogroup"
        :aria-label="label"
        class="flex flex-wrap gap-2"
        @keydown="onKeydown"
    >
        <button
            v-for="name in options"
            :key="name"
            type="button"
            role="radio"
            :aria-checked="model === name"
            :tabindex="focusedName === name ? 0 : -1"
            class="flex w-16 flex-col items-center gap-1 rounded p-1 transition duration-fast ease-product"
            @click="select(name)"
        >
            <span
                class="flex h-12 w-12 items-center justify-center rounded border transition duration-fast ease-product"
                :class="model === name ? 'border-ink' : 'border-rule'"
                :style="{ backgroundColor: `var(--brand-${name})` }"
            >
                <svg
                    v-if="model === name"
                    class="h-4 w-4 text-white"
                    viewBox="0 0 16 16"
                    fill="none"
                    aria-hidden="true"
                    focusable="false"
                >
                    <path
                        d="M3.5 8.5 6.5 11.5 12.5 5"
                        stroke="currentColor"
                        stroke-width="2"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </span>
            <span class="text-12" :class="model === name ? 'text-ink' : 'text-ink-2'">{{ display(name) }}</span>
        </button>
    </div>
</template>
