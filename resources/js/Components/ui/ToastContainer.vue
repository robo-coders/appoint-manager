<script setup lang="ts">
import Toast from '@/Components/ui/Toast.vue';
import { dismissToast, useToasts } from '@/lib/toast';

const state = useToasts();
</script>

<template>
    <Teleport to="body">
        <div
            class="pointer-events-none fixed bottom-4 right-4 z-[60] flex w-80 max-w-[calc(100vw-2rem)] flex-col gap-2"
            aria-live="polite"
            aria-atomic="false"
            data-testid="toast-container"
        >
            <Toast
                v-for="item in state.items"
                :key="item.id"
                :item="item"
                class="appear"
                @dismiss="dismissToast(item.id)"
            />
        </div>
    </Teleport>
</template>
