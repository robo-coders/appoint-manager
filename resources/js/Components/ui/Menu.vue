<script setup lang="ts">
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * A small action menu. Row actions live in one of these rather than as a row of
 * inline links, so a table row has one affordance instead of five.
 */
withDefaults(defineProps<{ label?: string; align?: 'left' | 'right' }>(), {
    label: 'Actions',
    align: 'right',
});

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);

const close = (restoreFocus = true) => {
    if (!open.value) return;
    open.value = false;
    if (restoreFocus) trigger.value?.focus();
};

const toggle = async () => {
    open.value = !open.value;
    if (!open.value) return;
    await nextTick();
    items()[0]?.focus();
};

const items = () => Array.from(root.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

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
    if (open.value && root.value && !root.value.contains(event.target as Node)) close(false);
};

onMounted(() => document.addEventListener('mousedown', onOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));
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

        <div
            v-if="open"
            role="menu"
            class="appear absolute z-30 mt-1 min-w-44 rounded border border-rule bg-white py-1"
            :class="align === 'right' ? 'right-0' : 'left-0'"
            @click="close(false)"
        >
            <slot />
        </div>
    </div>
</template>
