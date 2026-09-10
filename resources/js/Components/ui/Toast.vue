<script setup lang="ts">
import type { ToastItem } from '@/lib/toast';

const props = defineProps<{ item: ToastItem }>();

const emit = defineEmits<{ dismiss: [] }>();

const runAction = () => {
    props.item.action?.run();
    emit('dismiss');
};
</script>

<template>
    <div
        class="pointer-events-auto flex items-start gap-3 rounded px-3 py-2.5 text-13 text-paper"
        :class="item.tone === 'error' ? 'bg-accent' : 'bg-ink'"
        :role="item.tone === 'error' ? 'alert' : 'status'"
        data-testid="toast"
        :data-tone="item.tone"
    >
        <p class="min-w-0 flex-1 break-words">{{ item.message }}</p>

        <button
            v-if="item.action"
            type="button"
            class="min-h-tap shrink-0 text-paper underline decoration-paper/60 underline-offset-4 hover:decoration-paper"
            data-testid="toast-action"
            @click="runAction"
        >
            {{ item.action.label }}
        </button>

        <button
            type="button"
            class="shrink-0 text-paper/80 transition duration-fast ease-product hover:text-paper"
            aria-label="Dismiss"
            data-testid="toast-dismiss"
            @click="emit('dismiss')"
        >
            <svg width="12" height="12" viewBox="0 0 14 14" aria-hidden="true">
                <path d="M2 2l10 10M12 2L2 12" stroke="currentColor" stroke-width="1.5" />
            </svg>
        </button>
    </div>
</template>
