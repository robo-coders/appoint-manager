<script setup lang="ts">
withDefaults(
    defineProps<{
        time: string;
        title: string;
        detail?: string | null;
        tone?: 'confirmed' | 'pending' | 'cancelled' | 'current' | 'freed';
        past?: boolean;
        overrunMinutes?: number;
        overlapping?: boolean;
        ariaLabel?: string;
    }>(),
    { tone: 'confirmed', past: false, overrunMinutes: 0, overlapping: false },
);

const emit = defineEmits<{ open: [] }>();

const BORDERS = {
    freed: 'border-l-accent',
    cancelled: 'border-l-danger',
    current: 'border-l-ink',
    pending: 'border-l-ink-3',
    confirmed: 'border-l-ink-4',
} as const;
</script>

<template>
    <button
        type="button"
        class="h-full w-full overflow-hidden rounded-none border-b border-b-rule border-l-2 bg-paper px-2 py-1 text-left transition duration-fast ease-product hover:bg-paper-sunk"
        :class="[BORDERS[tone], past ? 'text-ink-2' : 'text-ink']"
        :aria-label="ariaLabel"
        @click="emit('open')"
    >
        <span class="flex items-baseline gap-2">
            <span class="numeral shrink-0 text-12" :class="tone === 'current' ? 'font-medium' : ''">{{ time }}</span>
            <span class="truncate text-12" :class="tone === 'current' ? 'font-medium' : ''">
                <span v-if="tone === 'freed'" class="font-medium text-accent">Freed — </span>{{ title }}
            </span>
        </span>

        <span v-if="!past && detail" class="mt-0.5 block truncate text-12 text-ink-2">{{ detail }}</span>

        <span v-if="overrunMinutes" class="mt-0.5 block truncate text-12 text-ink-2">
            Runs <span class="numeral">{{ overrunMinutes }}</span> min over
        </span>

        <span v-if="overlapping" class="mt-0.5 block truncate text-12 text-danger">Double-booked</span>
    </button>
</template>
