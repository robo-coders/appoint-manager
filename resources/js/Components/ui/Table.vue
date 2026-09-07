<script setup lang="ts" generic="T extends Record<string, unknown>">
import { Link, router } from '@inertiajs/vue3';
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
    /**
     * What this column becomes below md, where the table is a **list of rows**.
     *
     *   'title' — the row's headline. Exactly one column takes it.
     *   'line'  — joins the second line, in column order, separated by middots.
     *   'meta'  — the muted value hard right, beside the row's action menu.
     *   omitted — not shown in the narrow state at all.
     *
     * A table where no column declares one keeps the old behaviour: the table
     * itself, scrolling sideways inside its own box. That is deliberate — it
     * means adopting the narrow state is a per-screen decision, and a screen
     * nobody has designed a phone layout for is not silently given a bad one.
     */
    narrow?: 'title' | 'line' | 'meta';
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
        /**
         * `card` is a table inside a white box with a hairline round it, which
         * is what a table nested in a page of other things needs to be read as
         * one object.
         *
         * `bare` is the table that *is* the page. The redesign draws Bookings
         * and the Waitlist with no box at all: rows on paper, separated by the
         * same hairline the rest of the product composes with, the header a row
         * of small caps over them and nothing else. A white card on warm paper
         * is a second surface, and on a screen whose only content is the table
         * that surface is doing no work — it just moves the list 1px off the
         * page it already fills. Rows breathe here too: `--row-h` is the height
         * of a *dense* row in a card, and out on the page the redesign gives
         * each one 12px a side instead.
         */
        chrome?: 'card' | 'bare';
        /** Set when the server owns sorting. Otherwise the table sorts itself. */
        sort?: { key: string; direction: 'asc' | 'desc' } | null;
        /**
         * The order the rows *arrive* in, for a self-sorting table whose caller
         * has already ordered them.
         *
         * The waitlist is a queue: the server hands it over oldest-wait-first
         * because that is what the list means, and the header then drew no
         * marker at all — so the one column the whole screen is ordered by
         * looked unsorted, and "Waited ↓" in the redesign looked like something
         * the app had dropped. This seeds the marker without taking sorting away
         * from the table: a press still re-sorts locally from here.
         */
        initialSort?: { key: string; direction: 'asc' | 'desc' } | null;
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
        /**
         * The row's own destination, and what makes the **whole row** a click
         * target rather than one item inside a menu.
         *
         * The redesign draws a bookings row as a single `<button onClick>`: the
         * row is the affordance, it takes the pointer cursor, and clicking
         * anywhere on it opens the record. The app had that as `Open` inside the
         * `⋯` menu — two clicks and a read to do the thing the list exists for,
         * on a row that already lit up on hover and then did nothing. A hover
         * highlight over a dead row is the lie; this is the fix.
         *
         * It is a *link*, not a click handler bolted to a `<tr>`. The cell named
         * by `rowLinkColumn` renders a real `<a>` round its content, so keyboard
         * focus, Enter, middle-click, ⌘-click and "copy link address" all work
         * the way they do everywhere else. The row handler is what extends that
         * one anchor's reach to the other 900 pixels, and it stands aside for
         * anything inside the row that is itself operable — the actions menu, a
         * `tel:` link, a checkbox — and for a modified click or a drag that
         * selected text.
         *
         * Return null for a row with nowhere to go: rows in one list are then
         * consistently inert rather than half of them being live.
         */
        rowHref?: (row: T) => string | null | undefined;
        /**
         * Per-row classes, for a row whose *state* changes how it is drawn — a
         * cancelled booking recedes rather than shouting, which is the redesign's
         * `opacity:.86` on that row and the reason the amount beside it is struck
         * through rather than boxed in red.
         */
        rowClass?: (row: T) => string;
        /**
         * Which column carries the real anchor. Defaults to the `narrow: 'title'`
         * column, else the first — which is the row's headline either way, and
         * the word somebody would have clicked if only one thing could be.
         */
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
    // When `sort` is passed the server is authoritative and rows arrive ordered.
    if (props.sort !== null || !localSort.value) return props.rows;
    // Likewise for the seeded marker until somebody actually presses a header:
    // the caller has already put the rows in this order, and re-sorting them on
    // a derived column ("9 d 04 h" is rendered from a number) would reorder a
    // queue for no reason.
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

// The skeleton is shaped from the same column definitions the header uses, so
// the two can never disagree about how many columns there are.
const skeletonFractions = ['w-3/4', 'w-1/2', 'w-2/3', 'w-5/6', 'w-1/3', 'w-4/5'];
const barWidth = (row: number, column: number) => skeletonFractions[(row + column * 2) % skeletonFractions.length];

/* ---- the narrow state -------------------------------------------------
 *
 * Below md this stops being a table.
 *
 * A table is a grid because comparing down a column is the point. On a 375px
 * screen there is no column to compare down: the amount and the row menu were
 * off the right-hand edge behind a horizontal scroll nobody discovers, names
 * broke across two and three lines, and rows went ragged as a result. What a
 * salon owner is doing on a phone is not comparing — it is *finding one person*
 * and acting on them, which is a list.
 *
 * So: a list of rows, headline over a second line, one muted value hard right,
 * and the same action menu. Same rows, same `cell:` slots, same data — a
 * different shape for a different job.
 *
 * The sort controls live in the header and go with it. That is a real loss and
 * an accepted one: sorting 348 customers by booking count is a desk task, and
 * every screen that adopts this has a search field above it, which is the phone
 * answer to the same question.
 */
const narrowColumns = computed(() => props.columns.filter((column) => column.narrow !== undefined));
const isNarrow = computed(() => narrowColumns.value.length > 0);

const titleColumn = computed(() => props.columns.find((column) => column.narrow === 'title') ?? null);
const lineColumns = computed(() => props.columns.filter((column) => column.narrow === 'line'));
const metaColumns = computed(() => props.columns.filter((column) => column.narrow === 'meta'));

/* ---- the row as a click target ----------------------------------------
 *
 * See `rowHref` above. Three pieces, and all three are load-bearing:
 *
 *   `hrefFor`      — the destination, or null for an inert row.
 *   `linkColumn`   — the one cell that renders a real anchor.
 *   `onRowClick`   — extends that anchor to the rest of the row.
 *
 * `INTERACTIVE` is the list of things that own their own click. Without it a
 * press on the actions menu would open the row *and* the menu, and a press on
 * a phone number would navigate away from the number somebody was dialling.
 * `closest()` rather than a target check, because the press usually lands on
 * a `<span>` inside the control rather than on the control.
 */
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

    // A modified click is a deliberate "somewhere else": let the anchor in the
    // link column handle it, or let nothing happen if the press missed it.
    if (event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;
    if ((event.target as Element | null)?.closest(INTERACTIVE)) return;
    // A drag that selected text is reading, not navigating.
    if ((window.getSelection()?.toString() ?? '') !== '') return;

    router.get(href);
};
</script>

<template>
    <div>
        <!-- ============================================================
             Below md: rows. See "the narrow state" above.
             ============================================================ -->
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

                        <!--
                            The second line, as a sentence rather than as more
                            columns. Middots between the parts, and `flex-wrap`
                            so a long one breaks between parts instead of mid-word.
                        -->
                        <p v-if="lineColumns.length" class="caption mt-1 flex flex-wrap items-baseline gap-x-2">
                            <!--
                                The middot travels with the part that follows it,
                                not as a flex child of its own. As a child it
                                could be the last thing before a wrap, which
                                leaves a line ending in a dangling "·" that reads
                                as truncated text. Attached, a wrapped line opens
                                with the separator instead, which reads as the
                                continuation it is.
                            -->
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

                    <!--
                        The right-hand block is a **stack**, not a row.

                        Two meta values side by side — a status badge and a price
                        — take about 145px of the 271px a 375px row has, which
                        leaves too little for the name. Side by side they also
                        forced the third and fourth part of the second line to
                        wrap, and a wrapped part carries its separator, so the
                        row ended with a line reading "· Confirmed" under a
                        dangling staff name. Stacked, the block is only as wide
                        as its widest value and the second line fits in one.

                        `items-baseline` on the row plus `flex-col` here is what
                        aligns the first meta value with the headline: a column
                        flex container takes its baseline from its first item.
                    -->
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
                    <!-- Hairline under the header only: the width is on the bottom,
                         so the colour must be too. `border-rule` would paint all
                         four sides. -->
                    <tr class="border-b" :class="bare ? 'border-b-rule-strong bg-paper' : 'border-b-rule bg-paper-sunk'">
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
                                class="group inline-flex items-center gap-1 transition duration-fast ease-product hover:text-ink"
                                :class="bare ? 'eyebrow' : 'caption'"
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
                            <span v-else :class="bare ? 'eyebrow' : 'caption'">{{ column.label }}</span>
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
                    <!--
                        `cursor-pointer` only where the row actually goes
                        somewhere. A row that lights up on hover and keeps the
                        text cursor is a control that says it is one and is not.
                    -->
                    <tr
                        v-for="row in sorted"
                        :key="String(row[rowKey])"
                        class="border-b border-b-rule transition duration-fast ease-product last:border-b-0 hover:bg-paper-sunk"
                        :class="[hrefFor(row) ? 'cursor-pointer' : '', rowClass ? rowClass(row) : '']"
                        @click="onRowClick(row, $event)"
                    >
                        <td
                            v-for="column in columns"
                            :key="column.key"
                            :class="[bare ? 'py-3' : 'h-row', cellClasses(column)]"
                        >
                            <!--
                                The real anchor, on the row's headline. Everything
                                else in the row is reached by `onRowClick`; this is
                                the part a keyboard, a middle click and a screen
                                reader can reach. `text-inherit` and no underline
                                because the *row* is the affordance here — a blue
                                link inside a clickable row is two controls drawn
                                where there is one.
                            -->
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
                        <!--
                            One affordance per row, not five. The menu owns its own
                            keyboard handling: Enter/Space opens, arrows move,
                            Escape closes and restores focus to the trigger.
                        -->
                        <td v-if="$slots.actions" class="w-col-actions px-pad-x text-right" :class="bare ? 'py-3' : 'h-row'">
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

            **On its own rule, with the paging on the same line.** The redesign
            closes the list with a hairline and sets the count and "Load more" as
            one row across it; the app had a floating caption 8px under the last
            row and then a separate block of buttons under *that*, so the list had
            no bottom edge and its two footers read as two unrelated things. The
            rule is what makes the count belong to the table above it rather than
            to the page below it.
        -->
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
