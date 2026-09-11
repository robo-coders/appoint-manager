<script setup lang="ts">
import Spinner from '@/Components/ui/Spinner.vue';
import { computed, onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        dirty: boolean;
        processing: boolean;
        savedAt?: number | null;
        holdSeconds?: number;
    }>(),
    { savedAt: null, holdSeconds: 60 },
);

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;

onMounted(() => (timer = setInterval(() => (now.value = Date.now()), 10_000)));
onBeforeUnmount(() => timer && clearInterval(timer));

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
