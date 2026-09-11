<script setup lang="ts">
export type SkeletonColumn = {
    width?: string;
    align?: 'left' | 'right';
    secondary?: boolean;
};

withDefaults(
    defineProps<{
        shape?: 'bar' | 'text' | 'heading' | 'block' | 'row' | 'card' | 'stat';
        lines?: number;
        width?: string;
        columns?: SkeletonColumn[];
    }>(),
    { shape: 'text', lines: 3, width: 'w-full', columns: () => [] },
);

const FRACTIONS = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-1/3', 'w-4/5'];
const barWidth = (row: number, column: number) => FRACTIONS[(row + column * 2) % FRACTIONS.length];
</script>

<template>
    <div role="status" aria-label="Loading" class="animate-pulse" :class="shape === 'bar' ? 'w-full' : ''">
        <div v-if="shape === 'bar'" class="h-skeleton rounded bg-ink-4" :class="width" />

        <template v-else-if="shape === 'text'">
            <div class="space-y-2">
                <div
                    v-for="line in lines"
                    :key="line"
                    class="h-skeleton rounded bg-ink-4"
                    :class="line === lines ? 'w-2/3' : width"
                />
            </div>
        </template>

        <div v-else-if="shape === 'heading'" class="h-6 w-48 rounded bg-ink-4" />

        <div v-else-if="shape === 'block'" class="h-32 rounded bg-ink-4" :class="width" />

        <template v-else-if="shape === 'row'">
            <div v-if="columns.length === 0" class="flex h-row items-center border-b border-b-rule">
                <div class="h-skeleton w-full rounded bg-ink-4" />
            </div>
            <div
                v-for="line in columns.length ? lines : 0"
                v-else
                :key="line"
                class="flex h-row items-center gap-4 border-b border-b-rule"
            >
                <div
                    v-for="(column, index) in columns"
                    :key="index"
                    class="flex"
                    :class="[
                        column.width ?? 'flex-1',
                        column.align === 'right' ? 'justify-end' : 'justify-start',
                        column.secondary ? 'hidden md:flex' : '',
                    ]"
                >
                    <div class="h-skeleton rounded bg-ink-4" :class="barWidth(line, index)" />
                </div>
            </div>
        </template>

        <div v-else-if="shape === 'card'" class="space-y-3 rounded border border-rule bg-white p-4">
            <div class="h-skeleton w-24 rounded bg-ink-4" />
            <div class="h-6 w-40 rounded bg-ink-4" />
            <div class="h-skeleton w-full rounded bg-ink-4" />
        </div>

        <div v-else class="space-y-2">
            <div class="h-skeleton w-20 rounded bg-ink-4" />
            <div class="h-8 w-24 rounded bg-ink-4" />
        </div>
    </div>
</template>
