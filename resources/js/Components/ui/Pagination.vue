<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{ page: number; perPage: number; total: number }>();
const emit = defineEmits<{ change: [number] }>();

const pages = computed(() => Math.max(1, Math.ceil(props.total / props.perPage)));
const from = computed(() => (props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1));
const to = computed(() => Math.min(props.page * props.perPage, props.total));
</script>

<template>
    <div class="flex items-center justify-between gap-4 py-2">
        <p class="text-12 text-ink-2">
            <span class="numeral">{{ from }}–{{ to }}</span> of <span class="numeral">{{ total }}</span>
        </p>
        <div class="flex items-center gap-1">
            <button
                type="button"
                class="inline-flex h-8 items-center rounded px-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink disabled:pointer-events-none disabled:text-ink-3"
                :disabled="page <= 1"
                @click="emit('change', page - 1)"
            >
                Previous
            </button>
            <span class="numeral px-2 text-12 text-ink-2">{{ page }} / {{ pages }}</span>
            <button
                type="button"
                class="inline-flex h-8 items-center rounded px-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink disabled:pointer-events-none disabled:text-ink-3"
                :disabled="page >= pages"
                @click="emit('change', page + 1)"
            >
                Next
            </button>
        </div>
    </div>
</template>
