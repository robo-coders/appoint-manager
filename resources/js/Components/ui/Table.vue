<script setup lang="ts" generic="T extends Record<string, unknown>">
import { computed, ref } from 'vue';
import EmptyState from './EmptyState.vue';
import Menu from './Menu.vue';
import Skeleton from './Skeleton.vue';

/**
 * The load-bearing component. Sortable, sticky header, hairline rows, no zebra,
 * numbers right-aligned in mono, row actions in one menu rather than five inline
 * links, an empty state built in, and a loading skeleton shaped to the real
 * columns.
 *
 * Column widths are token names, not arbitrary Tailwind classes. The mockups
 * carried 40/56/96/110/132/150/168 as ad-hoc pixels that nothing guarded; these
 * resolve to `--col-*` in tokens.css and are all on the 4px grid.
 */
export type ColumnWidth = 'when' | 'time' | 'staff' | 'status' | 'amount' | 'actions';

export type Column = {
    key: string;
    label: string;
    /** Numbers, times and money go right and mono so columns align on the decimal. */
    align?: 'left' | 'right';
    numeric?: boolean;
    sortable?: boolean;
    /** A named column width from tokens.css. Omit for a column that fills. */
    width?: ColumnWidth;
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
        /** Accessible name. A table with no caption is a table nobody can place. */
        label?: string;
        emptyTitle?: string;
        emptyDescription?: string;
        /**
         * The accessible name for a row's action menu, built from the row.
         *
         * `bookings-table.html` labels each one "Actions for Naomi Ellery,
         * 10 March 09:00" — seven identical "Actions" buttons is seven
         * identical announcements, and a screen-reader user tabbing the column
         * has no way to tell which row they are on.
         */
        rowLabel?: (row: T) => string;
    }>(),
    {
        rowKey: 'id',
        loading: false,
        loadingRows: 6,
        sticky: true,
        sort: null,
        emptyTitle: 'Nothing here yet',
    },
);

const emit = defineEmits<{ sort: [{ key: string; direction: 'asc' | 'desc' }]; rowClick: [T] }>();

const WIDTHS: Record<ColumnWidth, string> = {
    when: 'w-col-when',
    time: 'w-col-time',
    staff: 'w-col-staff',
    status: 'w-col-status',
    amount: 'w-col-amount',
    actions: 'w-col-actions',
};

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

const cellClasses = (column: Column) => [
    'px-pad-x',
    column.align === 'right' ? 'text-right' : 'text-left',
    column.numeric ? 'numeral' : '',
    column.secondary ? 'hidden md:table-cell' : '',
    column.width ? WIDTHS[column.width] : '',
];

// The skeleton is shaped from the same column definitions the header uses, so
// the two can never disagree about how many columns there are.
const skeletonFractions = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-1/3', 'w-4/5'];
const barWidth = (row: number, column: number) => skeletonFractions[(row + column * 2) % skeletonFractions.length];
</script>

<template>
    <div>
        <div class="overflow-x-auto rounded border border-rule bg-white">
            <table class="w-full border-collapse text-13" :aria-label="label">
                <thead :class="sticky ? 'sticky top-0 z-10' : ''">
                    <!-- Hairline under the header only: the width is on the bottom,
                         so the colour must be too. `border-rule` would paint all
                         four sides. -->
                    <tr class="border-b border-b-rule bg-paper-sunk">
                        <th
                            v-for="column in columns"
                            :key="column.key"
                            scope="col"
                            class="h-row whitespace-nowrap font-normal"
                            :class="cellClasses(column)"
                            :aria-sort="
                                activeSort?.key === column.key
                                    ? activeSort.direction === 'asc'
                                        ? 'ascending'
                                        : 'descending'
                                    : column.sortable
                                      ? 'none'
                                      : undefined
                            "
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
                        <th v-if="$slots.actions" scope="col" class="w-col-actions px-pad-x">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <!--
                    Loading: one bar per column, in that column's own cell, so the
                    skeleton is the table's real shape rather than three bars and a
                    gap. Appears only after 200ms via the caller's own delay.
                -->
                <tbody v-if="loading">
                    <tr v-for="line in loadingRows" :key="line" class="border-b border-b-rule last:border-b-0">
                        <td v-for="(column, index) in columns" :key="column.key" class="h-row" :class="cellClasses(column)">
                            <span class="flex" :class="column.align === 'right' ? 'justify-end' : 'justify-start'">
                                <Skeleton shape="bar" :width="barWidth(line, index)" />
                            </span>
                        </td>
                        <td v-if="$slots.actions" class="h-row w-col-actions px-pad-x">
                            <span class="flex justify-end"><Skeleton shape="bar" width="w-4" /></span>
                        </td>
                    </tr>
                </tbody>

                <tbody v-else-if="sorted.length === 0">
                    <tr>
                        <td :colspan="columns.length + ($slots.actions ? 1 : 0)" class="px-pad-x py-8">
                            <slot name="empty">
                                <EmptyState :title="emptyTitle" :description="emptyDescription">
                                    <slot name="empty-action" />
                                </EmptyState>
                            </slot>
                        </td>
                    </tr>
                </tbody>

                <tbody v-else>
                    <!-- Hairline rows, square corners, no zebra striping. -->
                    <tr
                        v-for="row in sorted"
                        :key="String(row[rowKey])"
                        class="border-b border-b-rule transition duration-fast ease-product last:border-b-0 hover:bg-paper-sunk"
                    >
                        <td v-for="column in columns" :key="column.key" class="h-row" :class="cellClasses(column)">
                            <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                {{ row[column.key] ?? '—' }}
                            </slot>
                        </td>
                        <!--
                            One affordance per row, not five. The menu owns its own
                            keyboard handling: Enter/Space opens, arrows move,
                            Escape closes and restores focus to the trigger.
                        -->
                        <td v-if="$slots.actions" class="h-row w-col-actions px-pad-x text-right">
                            <Menu :label="rowLabel ? rowLabel(row) : 'Actions for this row'">
                                <slot name="actions" :row="row" />
                            </Menu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <!--
            "Showing 1-6 of 12", from `bookings-table.html`. A table with no
            count leaves you unable to tell a filtered list from a short one.
        -->
        <p v-if="$slots.footer && !loading" class="caption mt-2"><slot name="footer" /></p>
    </div>
</template>
