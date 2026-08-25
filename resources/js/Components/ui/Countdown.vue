<script setup lang="ts">
import { computed, onUnmounted, ref } from 'vue';

/**
 * A live mono countdown. Used for the waitlist offer and the checkout hold —
 * both places where the customer needs to know how long she actually has.
 */
const props = defineProps<{ until: string; expiredLabel?: string }>();

const emit = defineEmits<{ expired: [] }>();

const now = ref(Date.now());
const timer = window.setInterval(() => {
    now.value = Date.now();
    if (remainingMs.value <= 0) {
        window.clearInterval(timer);
        emit('expired');
    }
}, 1000);
onUnmounted(() => window.clearInterval(timer));

const remainingMs = computed(() => new Date(props.until).getTime() - now.value);

const text = computed(() => {
    const ms = remainingMs.value;
    if (Number.isNaN(ms)) return '';
    if (ms <= 0) return props.expiredLabel ?? 'expired';
    const total = Math.floor(ms / 1000);
    const m = Math.floor(total / 60);
    const s = total % 60;
    return `${String(m).padStart(2, '0')}:${String(s).padStart(2, '0')}`;
});

const urgent = computed(() => remainingMs.value > 0 && remainingMs.value < 2 * 60 * 1000);
</script>

<template>
    <span class="numeral tabular-nums" :class="urgent ? 'text-danger' : ''" aria-live="polite">{{ text }}</span>
</template>
