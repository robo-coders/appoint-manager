<script setup lang="ts">
import { useId } from 'vue';

/**
 * "Stay signed in on this computer", on the editorial surface.
 *
 * `ui/Checkbox` is the operator app's — 6px radius, `border-rule`, the app's
 * type scale. This is the artboards': a 16px square at 5px radius that fills
 * with ink when it is on.
 *
 * A real `<input type="checkbox">` under it, with the label wrapping it, so
 * the label text is a hit target and the control is announced, focusable and
 * togglable by keyboard with nothing here re-implementing any of that. The
 * tick is drawn with `background-clip: content-box` rather than the artboard's
 * inset shadow — the reason is beside `.ed-check-box` in the stylesheet.
 */
const model = defineModel<boolean>({ default: false });

defineProps<{ label: string }>();

const uid = useId();
</script>

<template>
    <label class="ed-check" :for="uid">
        <input :id="uid" v-model="model" type="checkbox" class="ed-check-box" />
        {{ label }}
    </label>
</template>
