<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A live `mm:ss` until an instant passes.
 *
 * The waitlist offer page has an expiry and used to render it as nothing at
 * all, which left the customer no way to know whether "claim this slot" was
 * still a real offer. A ticking number is the whole point: it is the reason to
 * decide now.
 *
 * Mono and tabular so the digits do not shuffle sideways every second — the one
 * place in the product where a jittering baseline would be genuinely
 * distracting.
 *
 * `aria-live="off"`. A screen reader announcing a new number once a second is
 * unusable; the expiry is also stated once, in prose, by the page around this.
 * `role="timer"` lets assistive tech read it on demand instead.
 */
const props = withDefaults(
    defineProps<{
        /** ISO instant. */
        expiresAt: string;
        /** Shown once the instant has passed. */
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

    // Past an hour, minutes-and-seconds is noise; hours and minutes is the unit.
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
