<script setup lang="ts">
/**
 * A tab strip that scrolls sideways rather than making the page do it.
 *
 * Four status filters with their counts is 420px of tabs, and a phone is 375.
 * Without `overflow-x-auto` here the strip pushed the document past the
 * viewport and the whole screen scrolled horizontally — which takes the header,
 * the table and the footer with it. The overflow belongs to the wide thing, not
 * to the page.
 *
 * `whitespace-nowrap` and `shrink-0` are the other half of it: a flex child
 * with neither wraps "Awaiting deposit" onto two lines and makes every tab in
 * the row 20px taller instead of scrolling.
 */
import { nextTick, ref } from 'vue';
const model = defineModel<string>({ required: true });

const props = withDefaults(
    defineProps<{
        tabs: Array<{ value: string; label: string; count?: number }>;
        /** Accessible name for the tablist. */
        label?: string;
        /**
         * `underline` is the section tab: a strip that divides a screen into
         * pages of itself, where the marker belongs on the rule.
         *
         * `filter` is a row of filters over a list that stays where it is. The
         * redesign draws them as 6px tints rather than as underlines, and that
         * is the honest shape: nothing below the strip is being replaced, so an
         * underline claims a relationship to the table that a filter has not
         * got. The selected one carries `--pill-neutral` and 500; the rest are
         * secondary ink at 400, which is the same two-step the status pills use.
         */
        variant?: 'underline' | 'filter';
    }>(),
    { variant: 'underline' },
);

const root = ref<HTMLElement | null>(null);

const select = async (value: string, moveFocus = false) => {
    model.value = value;
    if (!moveFocus) return;
    await nextTick();
    root.value?.querySelector<HTMLElement>('[aria-selected="true"]')?.focus();
};

const onKeydown = (event: KeyboardEvent) => {
    const keys = ['ArrowRight', 'ArrowLeft', 'Home', 'End'];
    if (!keys.includes(event.key)) return;

    event.preventDefault();
    const values = props.tabs.map((tab) => tab.value);
    const index = values.indexOf(model.value);

    if (event.key === 'Home') return select(values[0], true);
    if (event.key === 'End') return select(values[values.length - 1], true);

    const next = event.key === 'ArrowRight' ? index + 1 : index - 1;

    return select(values[(next + values.length) % values.length], true);
};
</script>

<template>
    <!--
        The rule and the `#end` block belong to the *strip*, and the tabs belong
        to the tablist inside it. Two reasons, and the second is the one that
        matters: a `role="tablist"` may contain tabs and nothing else, so hanging
        a spacer and a date form off the same element was telling assistive tech
        that a date picker is one of the filters. And separated, the end block is
        a sibling that can wrap to its own line on a phone while the tabs keep
        scrolling — put inside the scroller it sat past the right-hand edge
        behind a horizontal scroll nobody discovers, which is a worse place for a
        control than the second row it came from.
    -->
    <div
        class="border-b border-b-rule"
        :class="variant === 'filter' ? 'flex flex-wrap items-center gap-x-4 gap-y-3 pb-3' : ''"
    >
        <div
            ref="root"
            role="tablist"
            :aria-label="label"
            class="flex items-center gap-1 overflow-x-auto"
            :class="variant === 'filter' ? 'min-w-0 flex-1' : ''"
            @keydown="onKeydown"
        >
            <button
                v-for="tab in tabs"
                :key="tab.value"
                type="button"
                role="tab"
                :aria-selected="model === tab.value"
                :tabindex="model === tab.value ? 0 : -1"
                class="inline-flex shrink-0 items-center gap-2 whitespace-nowrap text-13 transition duration-fast ease-product"
                :class="[
                    variant === 'filter'
                        ? 'rounded px-3 py-1 hover:bg-ink-tint'
                        : '-mb-px h-control border-b-2 px-3',
                    model === tab.value
                        ? variant === 'filter'
                            ? 'bg-pill-neutral font-medium text-ink'
                            : 'border-b-ink text-ink'
                        : variant === 'filter'
                          ? 'text-ink-2 hover:text-ink'
                          : 'border-b-transparent text-ink-2 hover:text-ink',
                ]"
                @click="select(tab.value)"
            >
                {{ tab.label }}
                <span v-if="tab.count !== undefined" class="numeral text-12 text-ink-2">{{ tab.count }}</span>
            </button>
        </div>

        <!--
            The right-hand end of a filter strip.

            The redesign draws the date control on this line — "Sept 2026 ▾"
            pushed hard right against the same rule the filters sit on. Bookings
            had it as a labelled three-control form in a block of its own below,
            which is 90px of chrome across the top of the list to hold two dates
            that are usually empty. Same controls, same rule, and on anything
            wider than a phone no second row.

            Only the filter strip takes it: a section tab strip divides a screen
            into pages of itself and has no "and also" corner.
        -->
        <!--
            `min-w-0` and not `shrink-0`. As a `shrink-0` flex item this block
            sized itself to its content's max-content width, so a `w-full` child
            inside it resolved against a box that was already as wide as the
            child wanted to be — the two date fields never wrapped, and the
            document went wider than a 375px window. It shrinks now, and what is
            inside it decides how to reflow.
        -->
        <div v-if="variant === 'filter' && $slots.end" class="flex min-w-0 max-w-full items-center gap-2">
            <slot name="end" />
        </div>
    </div>
</template>
