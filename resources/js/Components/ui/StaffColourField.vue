<script setup lang="ts">
import Label from '@/Components/ui/Label.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import { STAFF_COLOURS } from '@/lib/staffColour';
import { nextTick, ref, useId } from 'vue';

/**
 * Which colour a staff member is in the diary.
 *
 * Six presets, not a colour wheel. The previous control was `<input
 * type="color">` — an operating-system picker dropped into a monochrome
 * product, which guarantees that some salon eventually ships a neon-yellow
 * groomer and it is *our* app that looks broken. Exactly the argument DESIGN.md
 * makes for tenant brand presets, and the same six values.
 *
 * A radio group with a roving tabindex: one tab stop for the whole set, arrows
 * to move, and the colour is never the only thing carrying the choice — each
 * swatch has a name, and the selected one is announced.
 */
const props = defineProps<{ error?: string }>();

const model = defineModel<string>({ required: true });

const uid = useId();
const root = ref<HTMLElement | null>(null);

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
    const values = STAFF_COLOURS.map((colour) => colour.value);
    const index = Math.max(0, values.indexOf(model.value));

    if (event.key === 'Home') return select(values[0], true);
    if (event.key === 'End') return select(values[values.length - 1], true);

    const step = event.key === 'ArrowRight' || event.key === 'ArrowDown' ? 1 : -1;

    return select(values[(index + step + values.length) % values.length], true);
};
</script>

<template>
    <div class="space-y-1">
        <Label>Diary colour</Label>
        <div ref="root" role="radiogroup" :aria-labelledby="uid" class="flex flex-wrap gap-2" @keydown="onKeydown">
            <span :id="uid" class="sr-only">Diary colour</span>
            <button
                v-for="colour in STAFF_COLOURS"
                :key="colour.value"
                type="button"
                role="radio"
                :aria-checked="model === colour.value"
                :aria-label="colour.label"
                :tabindex="model === colour.value || (!STAFF_COLOURS.some((c) => c.value === model) && colour === STAFF_COLOURS[0]) ? 0 : -1"
                class="flex min-h-tap items-center gap-2 rounded border px-3 text-13 transition duration-fast ease-product"
                :class="model === colour.value ? 'border-ink' : 'border-rule hover:border-rule-strong'"
                @click="select(colour.value)"
            >
                <span class="inline-block h-3 w-3 shrink-0 rounded" :style="{ backgroundColor: colour.value }" aria-hidden="true" />
                {{ colour.label }}
            </button>
        </div>
        <FieldError :message="props.error" />
    </div>
</template>
