<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, provide, ref } from 'vue';
import { MENU_CLOSE } from './menuClose';

/**
 * A small action menu. Row actions live in one of these rather than as a row of
 * inline links, so a table row has one affordance instead of five.
 *
 * **Closing is provided to the items rather than left to bubbling.** The panel
 * has an `@click` on it and a click on an item does bubble through it, but that
 * was not enough for the one case that matters: an item whose handler opens a
 * modal. `SuperAdmin/Index` has one — "Sign in as the owner…" opens the
 * impersonation confirm — and the menu stayed on screen underneath the dialog's
 * overlay, so a confirm that exists to be read carefully was read through a
 * second surface. An item now closes the menu itself, before its own handler
 * runs, which does not depend on what that handler goes on to do.
 *
 * The panel hangs below the trigger when there is room, and above it when the
 * last row of a list would otherwise open into the heading underneath. Same
 * panel, no extra motion.
 */
const props = withDefaults(defineProps<{ label?: string; align?: 'left' | 'right' }>(), {
    label: 'Actions',
    align: 'right',
});

const GAP = 4;
const MARGIN = 8;

const open = ref(false);
const placed = ref(false);
const root = ref<HTMLElement | null>(null);
const panel = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
const position = ref({ top: 0, left: 0 });

const close = (restoreFocus = true) => {
    if (!open.value) return;
    open.value = false;
    placed.value = false;
    if (restoreFocus) trigger.value?.focus();
};

/*
 * Handed to every `MenuItem` below this menu. `provide`/`inject` rather than a
 * prop because the items arrive through a slot, so the consumer would otherwise
 * have to wire this on each one and would forget on the one that matters.
 */
provide(MENU_CLOSE, () => close(false));

const toggle = async () => {
    if (open.value) return close();

    open.value = true;
    await nextTick();
    place();
    items()[0]?.focus();
};

/**
 * Open upward when the panel would run off the bottom of the viewport.
 * Same hairline panel, same type — only the edge it hangs from changes.
 */
const place = () => {
    const triggerEl = trigger.value;
    const panelEl = panel.value;

    if (!triggerEl || !panelEl) return;

    const rect = triggerEl.getBoundingClientRect();
    const { height, width } = panelEl.getBoundingClientRect();

    const spaceBelow = window.innerHeight - rect.bottom;
    const dropUp = height > 0 && spaceBelow < height + GAP && rect.top > spaceBelow;
    const alignedLeft = props.align === 'left' ? rect.left : rect.right - width;

    position.value = {
        top: dropUp ? Math.max(MARGIN, rect.top - height - GAP) : rect.bottom + GAP,
        left: Math.min(Math.max(MARGIN, alignedLeft), Math.max(MARGIN, window.innerWidth - width - MARGIN)),
    };

    placed.value = true;
};

const items = () => Array.from(panel.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') return close();
    // Tab out of an open menu closes it without stealing the focus move.
    if (event.key === 'Tab') return close(false);
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

    event.preventDefault();

    // Arrows on the closed trigger open the menu and land on an item.
    if (!open.value) return toggle();

    const list = items();
    if (!list.length) return;

    if (event.key === 'Home') return list[0].focus();
    if (event.key === 'End') return list[list.length - 1].focus();

    const index = list.indexOf(document.activeElement as HTMLElement);
    const next = event.key === 'ArrowDown' ? index + 1 : index - 1;
    list[(next + list.length) % list.length]?.focus();
};

const onOutside = (event: MouseEvent) => {
    if (!open.value) return;

    const target = event.target as Node;

    if (root.value?.contains(target) || panel.value?.contains(target)) return;

    close(false);
};

const reposition = () => {
    if (open.value) place();
};

onMounted(() => {
    document.addEventListener('mousedown', onOutside);
    window.addEventListener('scroll', reposition, true);
    window.addEventListener('resize', reposition);
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onOutside);
    window.removeEventListener('scroll', reposition, true);
    window.removeEventListener('resize', reposition);
});
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <button
            ref="trigger"
            type="button"
            class="inline-flex h-8 w-8 items-center justify-center rounded text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
            :aria-label="label"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="toggle"
        >
            <svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true">
                <circle cx="7" cy="2.5" r="1.25" />
                <circle cx="7" cy="7" r="1.25" />
                <circle cx="7" cy="11.5" r="1.25" />
            </svg>
        </button>

        <Teleport to="body">
            <div
                v-if="open"
                ref="panel"
                role="menu"
                class="appear fixed z-[45] min-w-44 rounded border border-rule bg-white py-1"
                :style="{
                    top: `${position.top}px`,
                    left: `${position.left}px`,
                    visibility: placed ? 'visible' : 'hidden',
                }"
                @click="close(false)"
                @keydown="onKeydown"
            >
                <slot />
            </div>
        </Teleport>
    </div>
</template>
