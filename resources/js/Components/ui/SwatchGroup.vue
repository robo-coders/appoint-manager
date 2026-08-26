<script setup lang="ts">
import { computed, nextTick, ref } from 'vue';

/**
 * Single-select colour swatches. A radio group that happens to look like paint.
 *
 * It lives in the library rather than on the branding screen because it is a
 * control, and controls in this product have exactly one implementation. A
 * hand-rolled version of this is the one that ships without arrow keys, without
 * `aria-checked`, and with the selected swatch marked only by being a slightly
 * different colour — which is unreadable to the people most likely to care what
 * colour it is.
 *
 * Three things carry the selection, only one of which is colour:
 *   - a tick inside the chosen swatch,
 *   - its name in ink beneath it while the others stay muted,
 *   - `aria-checked` for anything not looking at the screen.
 *
 * Takes NAMES, never hex. A name resolves to `var(--brand-forest)` at render
 * time, so what forest looks like stays a question only tokens.css answers.
 */
const model = defineModel<string | null>({ required: true });

const props = defineProps<{
    /** Preset names, in the order they should appear. */
    options: string[];
    /** Accessible name for the group — the visible field label. */
    label: string;
}>();

const root = ref<HTMLElement | null>(null);

const display = (name: string) => name.charAt(0).toUpperCase() + name.slice(1);

/*
 * Roving tabindex. One stop for the whole group, not six.
 *
 * With nothing chosen there is no checked radio to carry the tab stop, and ARIA
 * says the first option takes it instead — otherwise the group is unreachable
 * by keyboard in exactly the state a new salon is in.
 */
const focusedName = computed(() => (model.value && props.options.includes(model.value) ? model.value : props.options[0]));

const select = async (value: string, moveFocus = false) => {
    model.value = value;

    if (!moveFocus) return;

    await nextTick();
    root.value?.querySelector<HTMLElement>('[aria-checked="true"]')?.focus();
};

const onKeydown = (event: KeyboardEvent) => {
    // Both axes: the swatches wrap, so Down is as natural a "next" as Right.
    const keys = ['ArrowRight', 'ArrowDown', 'ArrowLeft', 'ArrowUp', 'Home', 'End'];
    if (!keys.includes(event.key)) return;

    event.preventDefault();

    const { options } = props;
    if (options.length === 0) return;

    if (event.key === 'Home') return select(options[0], true);
    if (event.key === 'End') return select(options[options.length - 1], true);

    /*
     * From "nothing chosen", either direction should land on a real option
     * rather than wrapping off the end of an index that was never valid.
     */
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
            <!--
                The swatch itself. Background is a token reference rather than a
                class, because the six presets are data from the server and
                Tailwind cannot generate a class for a name it has never seen.
            -->
            <span
                class="flex h-12 w-12 items-center justify-center rounded border transition duration-fast ease-product"
                :class="model === name ? 'border-ink' : 'border-rule'"
                :style="{ backgroundColor: `var(--brand-${name})` }"
            >
                <!--
                    White reads on all six: every preset clears 4.5:1 against it,
                    which is what `npm run check:contrast` exists to keep true.
                -->
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
