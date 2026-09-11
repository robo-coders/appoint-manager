<script setup lang="ts" generic="T extends Record<string, unknown>">
import { Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import EmptyState from './EmptyState.vue';
import Menu from './Menu.vue';
import Skeleton from './Skeleton.vue';

export type ColumnWidth = 'when' | 'time' | 'staff' | 'status' | 'amount' | 'actions';

export type Column = {
    key: string;
    label: string;
    align?: 'left' | 'right';
    numeric?: boolean;
    sortable?: boolean;
    width?: ColumnWidth;
    secondary?: boolean;
    narrowOnly?: boolean;
    narrow?: 'lead' | 'title' | 'line' | 'meta';
};

const props = withDefaults(
    defineProps<{
        columns: Column[];
        rows: T[];
        rowKey?: string;
        loading?: boolean;
        loadingRows?: number;
        sticky?: boolean;
        chrome?: 'card' | 'bare';
        sort?: { key: string; direction: 'asc' | 'desc' } | null;
        initialSort?: { key: string; direction: 'asc' | 'desc' } | null;
        label?: string;
        emptyTitle?: string;
        emptyDescription?: string;
        rowLabel?: (row: T) => string;
        rowHref?: (row: T) => string | null | undefined;
        rowClass?: (row: T) => string;
        rowLinkColumn?: string;
    }>(),
    {
        rowKey: 'id',
        loading: false,
        loadingRows: 6,
        sticky: true,
        chrome: 'card',
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

const localSort = ref<{ key: string; direction: 'asc' | 'desc' } | null>(props.initialSort ?? null);
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
    if (props.sort !== null || !localSort.value) return props.rows;
    if (localSort.value === props.initialSort) return props.rows;
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

const bare = computed(() => props.chrome === 'bare');

const cellClasses = (column: Column) => [
    'px-pad-x',
    column.align === 'right' ? 'text-right' : 'text-left',
    column.numeric ? 'numeral' : '',
    column.secondary ? 'hidden md:table-cell' : '',
    column.width ? WIDTHS[column.width] : '',
];

const skeletonFractions = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-1/3', 'w-4/5'];
const barWidth = (row: number, column: number) => skeletonFractions[(row + column * 2) % skeletonFractions.length];

const narrowColumns = computed(() => props.columns.filter((column) => column.narrow !== undefined));
const wideColumns = computed(() => props.columns.filter((column) => column.narrowOnly !== true));
const isNarrow = computed(() => narrowColumns.value.length > 0);

const leadColumn = computed(() => props.columns.find((column) => column.narrow === 'lead') ?? null);
const titleColumn = computed(() => props.columns.find((column) => column.narrow === 'title') ?? null);
const lineColumns = computed(() => props.columns.filter((column) => column.narrow === 'line'));
const metaColumns = computed(() => props.columns.filter((column) => column.narrow === 'meta'));

const INTERACTIVE = 'a,button,input,select,textarea,label,summary,[role="menu"],[role="menuitem"],[contenteditable="true"]';

const hrefFor = (row: T) => props.rowHref?.(row) ?? null;

const linkColumn = computed(() => {
    if (props.rowHref === undefined) return null;
    if (props.rowLinkColumn) return props.rowLinkColumn;

    return (props.columns.find((column) => column.narrow === 'title') ?? props.columns[0])?.key ?? null;
});

const onRowClick = (row: T, event: MouseEvent) => {
    const href = hrefFor(row);
    if (href === null) return;

    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if ((event.target as Element | null)?.closest(INTERACTIVE)) return;
    if ((window.getSelection()?.toString() ?? '') !== '') return;

    router.get(href);
};
</script>

<template>
    <div>
        <div v-if="isNarrow" class="md:hidden" :class="bare ? 'border-t border-t-rule-strong' : 'rounded border border-rule bg-white'">
            <ul v-if="loading">
                <li v-for="line in loadingRows" :key="line" class="border-b border-b-rule px-pad-x py-3 last:border-b-0">
                    <Skeleton shape="bar" :width="barWidth(line, 0)" />
                    <span class="mt-2 block"><Skeleton shape="bar" :width="barWidth(line, 1)" /></span>
                </li>
            </ul>

            <div v-else-if="sorted.length === 0" class="px-pad-x py-8">
                <slot name="empty">
                    <EmptyState :title="emptyTitle" :description="emptyDescription">
                        <slot name="empty-action" />
                    </EmptyState>
                </slot>
            </div>

            <ul v-else :aria-label="label">
                <li
                    v-for="row in sorted"
                    :key="String(row[rowKey])"
                    class="flex items-baseline gap-3 border-b border-b-rule px-pad-x py-3 last:border-b-0"
                    :class="[hrefFor(row) ? 'cursor-pointer' : '', rowClass ? rowClass(row) : '']"
                    @click="onRowClick(row, $event)"
                >
                    <div v-if="leadColumn" class="w-col-time shrink-0">
                        <slot
                            v-if="$slots[`narrow:${leadColumn.key}`]"
                            :name="`narrow:${leadColumn.key}`"
                            :row="row"
                            :value="row[leadColumn.key]"
                        />
                        <span v-else class="numeral text-17 text-ink">
                            <slot :name="`cell:${leadColumn.key}`" :row="row" :value="row[leadColumn.key]">
                                {{ row[leadColumn.key] ?? '—' }}
                            </slot>
                        </span>
                    </div>

                    <div class="min-w-0 flex-1">
                        <p v-if="titleColumn" class="text-14 text-ink">
                            <component
                                :is="hrefFor(row) ? Link : 'span'"
                                :href="hrefFor(row) ?? undefined"
                                class="block text-inherit no-underline"
                            >
                                <slot :name="`cell:${titleColumn.key}`" :row="row" :value="row[titleColumn.key]">
                                    {{ row[titleColumn.key] ?? '—' }}
                                </slot>
                            </component>
                        </p>

                        <p v-if="lineColumns.length" class="caption mt-1 flex flex-wrap items-baseline gap-x-2">
                            <span
                                v-for="(column, index) in lineColumns"
                                :key="column.key"
                                :class="column.numeric ? 'numeral' : ''"
                            >
                                <span v-if="index > 0" aria-hidden="true">·&nbsp;</span>
                                <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                    {{ row[column.key] ?? '—' }}
                                </slot>
                            </span>
                        </p>
                    </div>

                    <div class="flex shrink-0 items-baseline gap-2">
                        <div v-if="metaColumns.length" class="flex flex-col items-end gap-1">
                            <span
                                v-for="column in metaColumns"
                                :key="column.key"
                                class="whitespace-nowrap text-13"
                                :class="column.numeric ? 'numeral' : ''"
                            >
                                <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                    {{ row[column.key] ?? '—' }}
                                </slot>
                            </span>
                        </div>
                        <Menu v-if="$slots.actions" :label="rowLabel ? rowLabel(row) : 'Actions for this row'">
                            <slot name="actions" :row="row" />
                        </Menu>
                    </div>
                </li>
            </ul>
        </div>

        <div
            class="overflow-x-auto"
            :class="[isNarrow ? 'hidden md:block' : '', bare ? '' : 'rounded border border-rule bg-white']"
        >
            <table class="w-full border-collapse text-13" :aria-label="label">
                <thead :class="sticky ? 'sticky top-0 z-10' : ''">
                    <tr class="border-b" :class="bare ? 'border-b-rule-strong bg-paper' : 'border-b-rule bg-paper-sunk'">
                        <th
                            v-for="column in wideColumns"
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
                                class="group inline-flex items-center gap-1 transition duration-fast ease-product hover:text-ink"
                                :class="bare ? 'eyebrow' : 'caption'"
                                @click="toggleSort(column)"
                            >
                                {{ column.label }}
                                <span
                                    aria-hidden="true"
                                    class="text-12 transition duration-fast ease-product"
                                    :class="activeSort?.key === column.key ? 'opacity-100' : 'opacity-0 group-hover:opacity-60'"
                                    >{{ activeSort?.key === column.key && activeSort.direction === 'desc' ? '↓' : '↑' }}</span
                                >
                            </button>
                            <span v-else :class="bare ? 'eyebrow' : 'caption'">{{ column.label }}</span>
                        </th>
                        <th v-if="$slots.actions" scope="col" class="w-col-actions px-pad-x">
                            <span class="sr-only">Actions</span>
                        </th>
                    </tr>
                </thead>

                <tbody v-if="loading">
                    <tr v-for="line in loadingRows" :key="line" class="border-b border-b-rule last:border-b-0">
                        <td v-for="(column, index) in wideColumns" :key="column.key" class="h-row" :class="cellClasses(column)">
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
                        <td :colspan="wideColumns.length + ($slots.actions ? 1 : 0)" class="px-pad-x py-8">
                            <slot name="empty">
                                <EmptyState :title="emptyTitle" :description="emptyDescription">
                                    <slot name="empty-action" />
                                </EmptyState>
                            </slot>
                        </td>
                    </tr>
                </tbody>

                <tbody v-else>
                    <tr
                        v-for="row in sorted"
                        :key="String(row[rowKey])"
                        class="border-b border-b-rule transition duration-fast ease-product last:border-b-0 hover:bg-paper-sunk"
                        :class="[hrefFor(row) ? 'cursor-pointer' : '', rowClass ? rowClass(row) : '']"
                        @click="onRowClick(row, $event)"
                    >
                        <td
                            v-for="column in wideColumns"
                            :key="column.key"
                            :class="[bare ? 'py-3' : 'h-row', cellClasses(column)]"
                        >
                            <component
                                :is="linkColumn === column.key && hrefFor(row) ? Link : 'span'"
                                :href="linkColumn === column.key && hrefFor(row) ? hrefFor(row) : undefined"
                                class="block text-inherit no-underline"
                            >
                                <slot :name="`cell:${column.key}`" :row="row" :value="row[column.key]">
                                    {{ row[column.key] ?? '—' }}
                                </slot>
                            </component>
                        </td>
                        <td v-if="$slots.actions" class="w-col-actions px-pad-x text-right" :class="bare ? 'py-3' : 'h-row'">
                            <Menu :label="rowLabel ? rowLabel(row) : 'Actions for this row'">
                                <slot name="actions" :row="row" />
                            </Menu>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div
            v-if="($slots.footer || $slots['footer-action']) && !loading"
            class="mt-px flex flex-wrap items-center gap-3 border-t border-t-rule pt-3"
        >
            <p v-if="$slots.footer" class="caption"><slot name="footer" /></p>
            <span class="flex-1"></span>
            <div v-if="$slots['footer-action']" class="flex shrink-0 items-center gap-2">
                <slot name="footer-action" />
            </div>
        </div>
    </div>
</template>
