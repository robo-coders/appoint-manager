<script setup lang="ts">
import { Link, router } from '@inertiajs/vue3';
import { nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

/**
 * The signed-in person, pinned to the bottom of the nav rail.
 *
 * Separate from `UserMenu`, which is the top-bar version: this one is a 24px
 * ink square with an initial, a name, and a chevron, and its menu **opens
 * upward** because there is nothing below it to open into. The chevron points
 * up when closed and down when open, so the direction always says where the
 * menu will go rather than what state it is in — which is the version that
 * survives somebody glancing at it.
 *
 * "Log out" is `--danger`, below a hairline, and it is the only danger-coloured
 * text in the rail. The hairline is not decoration: it is what stops it being
 * the thing you hit by accident on the way to Billing.
 *
 * While impersonating, this whole area becomes a `--danger` bordered block. A
 * super admin borrowing a salon owner's session needs to be told so by the
 * chrome, permanently, in the one place they will always be looking — not by a
 * banner at the top of a page they have scrolled past.
 */
withDefaults(
    defineProps<{
        name: string;
        profileHref: string;
        billingHref?: string;
        logoutHref: string;
        /** Icon rail width: the initial only, and the menu opens beside it. */
        collapsed?: boolean;
        impersonating?: boolean;
        impersonatedTenant?: string | null;
        stopImpersonatingHref?: string;
    }>(),
    { collapsed: false, impersonating: false },
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

/*
 * Opening upward means the *last* item is the one nearest the trigger, so
 * ArrowUp lands there and ArrowDown lands on the top of the list. Focusing the
 * first item on open would jump the cursor to the far end of the menu.
 */
const openMenu = async (focus: 'first' | 'last' = 'last') => {
    open.value = true;
    await nextTick();
    const list = items();
    (focus === 'last' ? list[list.length - 1] : list[0])?.focus();
};

const onTriggerKeydown = (event: KeyboardEvent) => {
    if (event.key === 'ArrowUp') {
        event.preventDefault();

        return openMenu('last');
    }
    if (event.key === 'ArrowDown') {
        event.preventDefault();

        return openMenu('first');
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

const initial = (value: string) => (value.trim()[0] ?? '?').toUpperCase();

onMounted(() => document.addEventListener('mousedown', onOutside));
onBeforeUnmount(() => document.removeEventListener('mousedown', onOutside));
</script>

<template>
    <div ref="root" class="relative border-t border-t-rule p-2" @keydown="onKeydown">
        <!--
            Impersonating. Not a subtle tint: a border in --danger and the
            salon's name, so there is no version of this session where a super
            admin can forget whose diary they are typing in.
        -->
        <div v-if="impersonating" class="rounded border border-danger p-2">
            <p class="text-12 text-danger">Impersonating</p>
            <p class="mt-1 truncate text-13">{{ impersonatedTenant ?? 'this salon' }}</p>
            <button
                v-if="stopImpersonatingHref"
                type="button"
                class="mt-2 min-h-row w-full rounded px-2 text-left text-13 text-danger transition duration-fast ease-product hover:bg-paper"
                @click="post(stopImpersonatingHref)"
            >
                Stop impersonating
            </button>
        </div>

        <template v-else>
            <div
                v-if="open"
                role="menu"
                aria-label="Account"
                class="appear absolute bottom-full left-2 right-2 mb-1 rounded border border-rule bg-white p-1"
            >
                <Link
                    :href="profileHref"
                    role="menuitem"
                    class="block min-h-row w-full rounded px-2 py-1 text-left text-13 text-ink transition duration-fast ease-product hover:bg-paper-sunk"
                    @click="close(false)"
                >
                    Profile
                </Link>
                <Link
                    v-if="billingHref"
                    :href="billingHref"
                    role="menuitem"
                    class="block min-h-row w-full rounded px-2 py-1 text-left text-13 text-ink transition duration-fast ease-product hover:bg-paper-sunk"
                    @click="close(false)"
                >
                    Billing
                </Link>
                <hr role="separator" class="my-2" />
                <button
                    type="button"
                    role="menuitem"
                    class="block min-h-row w-full rounded px-2 py-1 text-left text-13 text-danger transition duration-fast ease-product hover:bg-paper-sunk"
                    @click="post(logoutHref)"
                >
                    Log out
                </button>
            </div>

            <button
                ref="trigger"
                type="button"
                class="flex min-h-row w-full items-center gap-2 rounded px-2 text-13 transition duration-fast ease-product hover:bg-paper"
                :class="open ? 'bg-ink-tint' : ''"
                :aria-expanded="open"
                aria-haspopup="menu"
                :aria-label="collapsed ? `Account: ${name}` : undefined"
                @click="open ? close() : openMenu()"
                @keydown="onTriggerKeydown"
            >
                <span
                    class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-ink text-12 font-medium text-white"
                    aria-hidden="true"
                >
                    {{ initial(name) }}
                </span>
                <span v-if="!collapsed" class="flex-1 truncate text-left">{{ name }}</span>
                <svg
                    v-if="!collapsed"
                    width="12"
                    height="12"
                    viewBox="0 0 12 12"
                    aria-hidden="true"
                    class="shrink-0 text-ink-2"
                >
                    <path
                        :d="open ? 'M2.5 4.5 6 8l3.5-3.5' : 'M2.5 7.5 6 4l3.5 3.5'"
                        fill="none"
                        stroke="currentColor"
                        stroke-width="1.25"
                        stroke-linecap="round"
                        stroke-linejoin="round"
                    />
                </svg>
            </button>
        </template>
    </div>
</template>
