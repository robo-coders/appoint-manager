<script setup lang="ts">
import { computed, ref, toRef } from 'vue';
import { useFocusTrap } from '@/lib/focusTrap';
import Button from './Button.vue';

const props = withDefaults(
    defineProps<{
        show: boolean;
        title: string;
        confirmLabel?: string;
        cancelLabel?: string;
        body?: string;
        tone?: 'danger' | 'primary' | 'accent';
        loading?: boolean;
    }>(),
    { confirmLabel: 'Confirm', cancelLabel: 'Keep it', tone: 'danger', loading: false },
);

const emit = defineEmits<{ close: []; confirm: [] }>();

const panel = ref<HTMLElement | null>(null);
useFocusTrap(panel, toRef(props, 'show'), () => emit('close'));

/*
 * `accent` is the filled terracotta, not the outlined one. An outlined accent
 * button beside a ghost cancel gives a dialog two quiet actions and no answer
 * to "which one is the button"; the fill is what makes the confirm the confirm.
 */
const confirmVariant = computed(() => (props.tone === 'accent' ? 'accent-solid' : props.tone));
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
                <p
                    v-if="$slots.icon"
                    class="mb-3 flex h-8 w-8 items-center justify-center rounded bg-accent-tint text-accent"
                >
                    <slot name="icon" />
                </p>
                <h2 class="text-17">{{ title }}</h2>
                <div class="mt-2 text-13 text-ink-2"><slot>{{ body }}</slot></div>
                <div class="mt-6 flex justify-end gap-2">
                    <Button variant="ghost" @click="emit('close')">{{ cancelLabel }}</Button>
                    <Button :variant="confirmVariant" :loading="loading" @click="emit('confirm')">
                        {{ confirmLabel }}
                    </Button>
                </div>
            </div>
        </div>
    </Teleport>
</template>
