<script setup lang="ts">
/**
 * Shaped like the content it replaces, never a generic bar and never a spinner.
 * Callers pass a shape, not a size.
 *
 * `shape="row"` takes the table's own columns. It used to draw three bars
 * regardless of how many columns the table had, so a five-column table loaded
 * with a visible gap on the right and then snapped into a different shape. One
 * bar per column, inside a cell of that column's real width.
 *
 * Bars are `--ink-4`, not `--paper-sunk`. A paper-sunk bar on a white table is
 * a 1.06:1 difference — invisible, which the gallery showed immediately: the
 * loading table rendered as four empty rows. ink-4 is the token for a disabled
 * fill, which is what a skeleton bar is.
 *
 * Bar widths are fractions of their column rather than named pixel values. The
 * mockups carried ten ad-hoc bar widths (40/56/80/88/104/112/120/150/168/188)
 * that nothing guarded; a fraction follows whatever the column is set to and
 * cannot drift from it.
 */
export type SkeletonColumn = {
    /** A width utility for the cell, e.g. 'w-col-status'. Omit for auto. */
    width?: string;
    align?: 'left' | 'right';
    /** Hidden below md, matching the table's own column. */
    secondary?: boolean;
};

withDefaults(
    defineProps<{
        shape?: 'bar' | 'text' | 'heading' | 'block' | 'row' | 'card' | 'stat';
        lines?: number;
        /** Tailwind width class for the last line, so text looks ragged not blocked. */
        width?: string;
        /** Required by `shape="row"`. One bar is drawn per entry. */
        columns?: SkeletonColumn[];
    }>(),
    { shape: 'text', lines: 3, width: 'w-full', columns: () => [] },
);

/*
 * A deterministic ragged edge. Real rows do not all end in the same place, and
 * a block of identical bars reads as a loading *graphic* rather than as the
 * content arriving. Indexed rather than random so the same table always draws
 * the same skeleton and nothing re-flows between frames.
 */
const FRACTIONS = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-1/3', 'w-4/5'];
const barWidth = (row: number, column: number) => FRACTIONS[(row + column * 2) % FRACTIONS.length];
</script>

<template>
    <!--
        `w-full` on the root matters for `shape="bar"`: the bar is a percentage
        of its cell, and a root that sizes to its content is a root of zero
        width, so the bar rendered as nothing at all. The gallery caught it —
        the loading table drew four empty rows.
    -->
    <div role="status" aria-label="Loading" class="animate-pulse" :class="shape === 'bar' ? 'w-full' : ''">
        <!-- One bar. `Table` composes these into a real row so the loading
             state inherits the actual column widths. -->
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

        <!--
            One `<tr>` per line, one `<td>` per column. Rendered as table rows so
            it can be dropped straight into a `<tbody>` and inherit the real
            column widths rather than approximating them.
        -->
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
