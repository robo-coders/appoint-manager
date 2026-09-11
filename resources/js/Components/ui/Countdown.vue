<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        expiresAt: string;
        expiredLabel?: string;
    }>(),
    { expiredLabel: '00:00' },
);

const emit = defineEmits<{ expired: [] }>();

const now = ref(Date.now());
let timer: ReturnType<typeof setInterval> | undefined;
let fired = false;

const remaining = computed(() => {
    const target = new Date(props.expiresAt).getTime();

    return Number.isNaN(target) ? 0 : Math.max(0, target - now.value);
});

const label = computed(() => {
    if (remaining.value === 0) {
        return props.expiredLabel;
    }

    const total = Math.floor(remaining.value / 1000);
    const minutes = Math.floor(total / 60);
    const seconds = total % 60;

    if (minutes >= 60) {
        return `${Math.floor(minutes / 60)}h ${String(minutes % 60).padStart(2, '0')}m`;
    }

    return `${String(minutes).padStart(2, '0')}:${String(seconds).padStart(2, '0')}`;
});

const tick = () => {
    now.value = Date.now();

    if (remaining.value === 0 && !fired) {
        fired = true;
        emit('expired');
    }
};

onMounted(() => {
    tick();
    timer = setInterval(tick, 1000);
});

onBeforeUnmount(() => timer && clearInterval(timer));
</script>

<template>
    <span role="timer" aria-live="off" class="numeral whitespace-nowrap">{{ label }}</span>
</template>
