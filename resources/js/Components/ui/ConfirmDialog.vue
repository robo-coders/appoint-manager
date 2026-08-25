<script setup lang="ts">
import { ref, toRef } from 'vue';
import { useFocusTrap } from '@/lib/focusTrap';
import Button from './Button.vue';

/**
 * Names the exact consequence before it happens. The body is not optional
 * decoration: "Cancel and refund £10 to Priya Raman" is the whole point of the
 * dialog, and "Are you sure?" is not.
 */
const props = withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        confirmLabel?: string;
        cancelLabel?: string;
        body?: string;
        tone?: 'danger' | 'primary';
        loading?: boolean;
    }>(),
    { confirmLabel: 'Confirm', cancelLabel: 'Keep it', tone: 'danger', loading: false },
);

const emit = defineEmits<{ close: []; confirm: [] }>();

const panel = ref<HTMLElement | null>(null);
useFocusTrap(panel, toRef(props, 'show'), () => emit('close'));
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-overlay" @click="emit('close')" />
            <div
                ref="panel"
                role="alertdialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
                class="appear relative w-full max-w-sm rounded border border-rule bg-white p-4"
            >
                <h2 class="text-17">{{ title }}</h2>
                <div class="mt-2 text-13 text-ink-2"><slot>{{ body }}</slot></div>
                <div class="mt-6 flex justify-end gap-2">
                    <Button variant="ghost" @click="emit('close')">{{ cancelLabel }}</Button>
                    <Button :variant="tone" :loading="loading" @click="emit('confirm')">{{ confirmLabel }}</Button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
