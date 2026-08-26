<script setup lang="ts">
import { ref, toRef } from 'vue';
import { useFocusTrap } from '@/lib/focusTrap';

const props = defineProps<{ show: boolean; title: string; description?: string }>();
const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);
useFocusTrap(panel, toRef(props, 'show'), () => emit('close'));
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex justify-end">
            <div class="absolute inset-0 bg-overlay" @click="emit('close')" />
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
                class="appear relative flex h-full w-full max-w-md flex-col border-l border-l-rule bg-white"
            >
                <header class="flex items-start justify-between gap-4 border-b border-b-rule px-4 py-3">
                    <div>
                        <h2 class="text-17">{{ title }}</h2>
                        <p v-if="description" class="mt-1 text-13 text-ink-2">{{ description }}</p>
                    </div>
                    <button
                        type="button"
                        class="-mr-1 inline-flex size-8 items-center justify-center rounded text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
                        aria-label="Close"
                        @click="emit('close')"
                    >
                        <svg width="14" height="14" viewBox="0 0 14 14" aria-hidden="true">
                            <path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.5" />
                        </svg>
                    </button>
                </header>
                <div class="flex-1 overflow-y-auto px-4 py-4"><slot /></div>
                <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-t-rule px-4 py-3">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
