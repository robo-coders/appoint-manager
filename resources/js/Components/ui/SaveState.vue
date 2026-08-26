<script setup lang="ts">
import Spinner from '@/Components/ui/Spinner.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

/**
 * Whether the form in front of you has been saved.
 *
 * Settings used to have a Save button and nothing else — press it, the page
 * quietly re-renders, and there is no way to tell a successful save from a
 * click that missed. The result is people pressing Save twice and then
 * reloading to check.
 *
 * Three states, in the order they happen:
 *
 *   - **unsaved changes** while the form differs from what was loaded
 *   - **saving** while the request is in flight
 *   - **saved just now / saved a minute ago** afterwards, fading to nothing
 *
 * It is `aria-live="polite"`: the message is worth announcing once it settles,
 * and polite means it waits for a gap rather than interrupting somebody
 * mid-field.
 */
const props = withDefaults(
    defineProps<{
        dirty: boolean;
        processing: boolean;
        /** `Date.now()` of the last successful save, or null. */
        savedAt?: number | null;
        /** Stop saying "saved" after this long. */
        holdSeconds?: number;
    }>(),
    { savedAt: null, holdSeconds: 60 },
);

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 10_000)));
onBeforeUnmount(() => timer && clearInterval(timer));

// A save that lands while the clock is between ticks should still read as
// "just now" immediately, not up to ten seconds later.
watch(
    () => props.savedAt,
    () => (now.value = Date.now()),
);

const sinceSaved = computed(() => (props.savedAt === null ? null : Math.floor((now.value - props.savedAt) / 1000)));

const message = computed(() => {
    if (props.processing) return 'Saving…';
    if (props.dirty) return 'Unsaved changes';
    if (sinceSaved.value === null || sinceSaved.value > props.holdSeconds) return '';
    if (sinceSaved.value < 10) return 'Saved';

    return `Saved ${Math.max(1, Math.round(sinceSaved.value / 60))} minute${sinceSaved.value >= 90 ? 's' : ''} ago`;
});
</script>

<template>
    <p class="caption flex items-center gap-2" aria-live="polite">
        <Spinner v-if="processing" />
        {{ message }}
    </p>
</template>
