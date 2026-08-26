<script setup lang="ts">
import { dismissToast, useToasts } from '@/lib/toast';

const state = useToasts();
</script>

<template>
    <div
        class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
        aria-live="polite"
        aria-atomic="false"
    >
        <div
            v-for="item in state.items"
            :key="item.id"
            class="appear pointer-events-auto flex items-start gap-3 rounded border bg-white px-3 py-2.5 text-13"
            :class="item.tone === 'danger' ? 'border-danger' : item.tone === 'success' ? 'border-rule' : 'border-rule'"
        >
            <span
                class="mt-1.5 size-1.5 shrink-0 rounded"
                :class="{ 'bg-danger': item.tone === 'danger', 'bg-ink': item.tone === 'success', 'bg-ink-3': item.tone === 'neutral' }"
                aria-hidden="true"
            />
            <p class="flex-1 text-ink">{{ item.message }}</p>
            <button
                v-if="item.action"
                type="button"
                class="shrink-0 text-13 text-ink underline decoration-rule underline-offset-4 hover:decoration-ink"
                @click="item.action.run(); dismissToast(item.id)"
            >
                {{ item.action.label }}
            </button>
            <button
                type="button"
                class="shrink-0 text-ink-2 transition duration-fast ease-product hover:text-ink"
                aria-label="Dismiss"
                @click="dismissToast(item.id)"
            >
                <svg width="12" height="12" viewBox="0 0 14 14" aria-hidden="true">
                    <path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.5" />
                </svg>
            </button>
        </div>
    </div>
</template>
