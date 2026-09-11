<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        name: string;
        detail?: string;
        profileHref: string;
        logoutHref: string;
        impersonating?: boolean;
        stopImpersonatingHref?: string;
    }>(),
    { impersonating: false },
);

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);

const items = () => Array.from(root.value?.querySelectorAll<HTMLElement>('[role="menuitem"]') ?? []);

const close = (restoreFocus = true) => {
    if (!open.value) return;
    open.value = false;
    if (restoreFocus) trigger.value?.focus();
};

const openMenu = async (focus: 'first' | 'last' = 'first') => {
    open.value = true;
    await nextTick();
    const list = items();
    (focus === 'first' ? list[0] : list[list.length - 1])?.focus();
};

const onTriggerKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        return openMenu('first');
    }
    if (event.key === 'ArrowUp') {
        event.preventDefault();
        return openMenu('last');
    }
};

const onKeydown = (event: KeyboardEvent) => {
    if (event.key === 'Escape') return close();
    if (event.key === 'Tab') return close(false);
    if (!['ArrowDown', 'ArrowUp', 'Home', 'End'].includes(event.key)) return;

    event.preventDefault();
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

const post = (href: string) => {
    close(false);
    router.post(href);
};

onMounted(() => document.addEventListener('mousedown', onOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <button
            ref="trigger"
            type="button"
            class="inline-flex min-h-tap items-center gap-2 rounded px-2 text-13 text-ink transition duration-fast ease-product hover:bg-paper-sunk"
            :aria-expanded="open"
            aria-haspopup="menu"
            @click="open ? close() : openMenu()"
            @keydown="onTriggerKeydown"
        >
            <span class="max-w-col-when truncate">{{ name }}</span>
            <span aria-hidden="true" class="text-12 text-ink-2">{{ open ? '▴' : '▾' }}</span>
        </button>

        <div
            v-if="open"
            role="menu"
            class="appear absolute right-0 z-30 mt-1 w-col-when min-w-max rounded border border-rule bg-white py-1"
        >
            <p v-if="detail" class="border-b border-b-rule px-3 pb-2 pt-1 text-12 text-ink-2">{{ detail }}</p>

            <Link
                :href="profileHref"
                role="menuitem"
                class="block min-h-tap px-3 py-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
                @click="close(false)"
            >
                Your profile
            </Link>

            <button
                v-if="impersonating && stopImpersonatingHref"
                type="button"
                role="menuitem"
                class="block min-h-tap w-full px-3 py-2 text-left text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
                @click="post(stopImpersonatingHref)"
            >
                Stop impersonating
            </button>

            <button
                type="button"
                role="menuitem"
                class="block min-h-tap w-full px-3 py-2 text-left text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
                @click="post(logoutHref)"
            >
                Log out
            </button>
        </div>
    </div>
</template>
