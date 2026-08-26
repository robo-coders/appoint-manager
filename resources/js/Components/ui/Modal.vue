<script setup lang="ts">
import { computed, ref, toRef } from 'vue';
import { useFocusTrap } from '@/lib/focusTrap';

const props = withDefaults(
    defineProps<{ show: boolean; title: string; description?: string; size?: 'sm' | 'md' | 'lg' }>(),
    { size: 'md' },
);

const emit = defineEmits<{ close: [] }>();

const panel = ref<HTMLElement | null>(null);
useFocusTrap(panel, toRef(props, 'show'), () => emit('close'));

const width = computed(() => ({ sm: 'max-w-sm', md: 'max-w-md', lg: 'max-w-lg' })[props.size]);
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-center justify-center p-4">
            <div class="absolute inset-0 bg-overlay" @click="emit('close')" />
            <div
                ref="panel"
                role="dialog"
                aria-modal="true"
                :aria-label="title"
                tabindex="-1"
                class="appear relative w-full rounded border border-rule bg-white"
                :class="width"
            >
                <header class="border-b border-b-rule px-4 py-3">
                    <h2 class="text-17">{{ title }}</h2>
                    <p v-if="description" class="mt-1 text-13 text-ink-2">{{ description }}</p>
                </header>
                <div class="px-4 py-4"><slot /></div>
                <footer v-if="$slots.footer" class="flex justify-end gap-2 border-t border-t-rule px-4 py-3">
                    <slot name="footer" />
                </footer>
            </div>
        </div>
    </Teleport>
</template>
