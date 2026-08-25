<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed, ref } from 'vue';
import Skeleton from './Skeleton.vue';

export type Column = {
    key: string;
    label: string;
    /** Numbers, times and money go right and mono so columns align on the decimal. */
    align?: 'left' | 'right';
    numeric?: boolean;
    sortable?: boolean;
    /** A Tailwind width class, e.g. 'w-32'. */
    width?: string;
    /** Hidden below md. Use for anything that is not the point of the row. */
    secondary?: boolean;
};

const props = withDefaults(
    defineProps<{
        columns: Column[];
        rows: T[];
        rowKey?: string;
        loading?: boolean;
        /** Rows to draw while loading — shaped like the real ones. */
        loadingRows?: number;
        /** Sticky header. On by default; off inside a short card. */
        sticky?: boolean;
        /** Set when the server owns sorting. Otherwise the table sorts itself. */
        sort?: { key: string; direction: 'asc' | 'desc' } | null;
    }>(),
    { rowKey: 'id', loading: false, loadingRows: 6, sticky: true, sort: null },
);

const emit = defineEmits<{ sort: [{ key: string; direction: 'asc' | 'desc' }]; rowClick: [T] }>();

const localSort = ref<{ key: string; direction: 'asc' | 'desc' } | null>(null);
const activeSort = computed(() => props.sort ?? localSort.value);

const toggleSort = (column: Column) => {
    if (!column.sortable) return;
    const current = activeSort.value;
    const direction = current?.key === column.key && current.direction === 'asc' ? 'desc' : 'asc';
    const next = { key: column.key, direction } as const;
    if (props.sort === null) localSort.value = next;
    emit('sort', next);
};

const sorted = computed(() => {
    // When `sort` is passed the server is authoritative and rows arrive ordered.
    if (props.sort !== null || !localSort.value) return props.rows;
    const { key, direction } = localSort.value;
    const factor = direction === 'asc' ? 1 : -1;
    return [...props.rows].sort((a, b) => {
        const x = a[key] as string | number | null;
        const y = b[key] as string | number | null;
        if (x === y) return 0;
        if (x === null || x === undefined) return 1;
        if (y === null || y === undefined) return -1;
        return (x < y ? -1 : 1) * factor;
    });
});

const cellClasses = (column: Column) =>
    [
        'px-3',
        column.align === 'right' ? 'text-right' : 'text-left',
        column.numeric ? 'numeral' : '',
        column.secondary ? 'hidden md:table-cell' : '',
        column.width ?? '',
    ].join(' ');
</script>

<template>
    <div class="overflow-x-auto rounded border border-rule bg-white">
        <table class="w-full border-collapse text-13">
            <thead :class="sticky ? 'sticky top-0 z-10' : ''">
                <tr class="border-b border-rule bg-paper-sunk">
                    <th
                        v-for="column in columns"
                        :key="column.key"
                        scope="col"
                        :class="cellClasses(column)"
                        :aria-sort="
                            activeSort?.key === column.key
                                ? activeSort.direction === 'asc'
                                    ? 'ascending'
                                    : 'descending'
                                : undefined
                        "
                        class="h-row whitespace-nowrap font-normal"
                    >
                        <button
                            v-if="column.sortable"
                            type="button"
                            class="group caption inline-flex items-center gap-1 transition duration-fast ease-product hover:text-ink"
                            @click="toggleSort(column)"
                        >
                            {{ column.label }}
                            <!-- The arrow is only ink when the column is actually
                                 sorted; otherwise it appears on hover. An always-on
                                 marker on every sortable column is just noise. -->
                            <span
                                aria-hidden="true"
                                class="text-12 transition duration-fast ease-product"
                                :class="activeSort?.key === column.key ? 'opacity-100' : 'opacity-0 group-hover:opacity-60'"
                                >{{ activeSort?.key === column.key && activeSort.direction === 'desc' ? '↓' : '↑' }}</span
                            >
                        </button>
                        <span v-else class="caption">{{ column.label }}</span>
                    </th>
                    <th v-if="$slots.actions" scope="col" class="w-12 px-3"><span class="sr-only">Actions</span></th>
                </tr>
            </thead>

            <tbody v-if="loading">
                <tr v-for="line in loadingRows" :key="line" class="border-b border-rule">
                    <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="px-3">
                        <Skeleton shape="row" :lines="1" />
                    </td>
                </tr>
            </tbody>

            <tbody v-else-if="sorted.length === 0">
                <tr>
                    <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="px-3 py-8">
                        <slot name="empty">
                            <p class="text-13 text-ink-2">Nothing here yet.</p>
                        </slot>
                    </td>
                </tr>
            </tbody>

            <tbody v-else>
                <!-- Hairline rows, square corners, no zebra striping. -->
                <tr
                    v-for="row in sorted"
                    :key="String(row[rowKey])"
                    class="border-b border-rule transition duration-fast ease-product last:border-0 hover:bg-paper-sunk"
                >
                    <td v-for="column in columns" :key="column.key" :class="cellClasses(column)" class="h-row">
                        <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                            {{ row[column.key] ?? '—' }}
                        </slot>
                    </td>
                    <td v-if="$slots.actions" class="h-row px-3 text-right">
                        <slot name="actions" :row="row" />
                    </td>
                </tr>
            </tbody>
        </table>
    </div>
</template>
