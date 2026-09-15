<script setup lang="ts">
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import KeyHint from '@/Components/ui/KeyHint.vue';
import Skeleton from '@/Components/ui/Skeleton.vue';
import { toast } from '@/lib/toast';
import { Link, router, usePage } from '@inertiajs/vue3';
import ChevronsUpDown from 'lucide-vue-next/dist/esm/icons/chevrons-up-down';
import LogOut from 'lucide-vue-next/dist/esm/icons/log-out';
import Search from 'lucide-vue-next/dist/esm/icons/search';
import UserRound from 'lucide-vue-next/dist/esm/icons/user-round';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';

const props = withDefaults(
    defineProps<{
        name: string;
        email: string;
        role?: string | null;
        profileHref: string;
        logoutHref: string;
        loginHref: string;
        version: string;
        collapsed?: boolean;
    }>(),
    { role: null, collapsed: false },
);

const emit = defineEmits<{ search: [] }>();

const appName = computed(() => (usePage().props.appName as string) ?? '');

const loaded = computed(() => props.name.trim() !== '');

const open = ref(false);
const confirming = ref(false);
const loggingOut = ref(false);
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

const openSearch = () => {
    close(false);
    emit('search');
};

const confirmLogout = () => {
    close(false);
    confirming.value = true;
};

const logOut = async () => {
    loggingOut.value = true;

    try {
        await window.axios.post(props.logoutHref);
        window.location.assign(props.loginHref);
    } catch {
        loggingOut.value = false;
        toast.error('Could not log you out. Check your connection and try again.');
    }
};

const initial = computed(() => (props.name.match(/[\p{L}\p{N}]/u)?.[0] ?? '').toUpperCase());

let stopNavigate: (() => void) | undefined;

onMounted(() => {
    document.addEventListener('mousedown', onOutside);
    stopNavigate = router.on('navigate', () => close(false));
});

onBeforeUnmount(() => {
    document.removeEventListener('mousedown', onOutside);
    stopNavigate?.();
});
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
        <div
            v-if="open"
            role="menu"
            aria-label="Account"
            class="appear absolute bottom-full left-0 right-0 mb-1 rounded border border-rule bg-white"
            data-testid="account-menu"
        >
            <div v-if="!loaded" class="p-3">
                <Skeleton shape="text" :lines="2" />
            </div>

            <template v-else>
                <p class="truncate px-3 py-2 text-12 text-ink-2">
                    Signed in as <span class="text-ink">{{ email }}</span>
                </p>

                <hr role="separator" />

                <div class="p-1">
                    <Link
                        :href="profileHref"
                        role="menuitem"
                        class="flex min-h-row w-full items-center gap-2 rounded px-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
                        @click="close(false)"
                    >
                        <UserRound :size="15" :stroke-width="1.8" class="shrink-0" aria-hidden="true" />
                        Profile
                    </Link>
                    <button
                        type="button"
                        role="menuitem"
                        class="flex min-h-row w-full items-center gap-2 rounded px-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
                        @click="openSearch"
                    >
                        <Search :size="15" :stroke-width="1.8" class="shrink-0" aria-hidden="true" />
                        Search
                        <KeyHint :keys="['⌘K']" class="ml-auto" />
                    </button>
                </div>

                <hr role="separator" />

                <div class="p-1">
                    <button
                        type="button"
                        role="menuitem"
                        class="flex min-h-row w-full items-center gap-2 rounded px-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-paper-sunk hover:text-ink"
                        data-testid="account-menu-logout"
                        @click="confirmLogout"
                    >
                        <LogOut :size="15" :stroke-width="1.8" class="shrink-0" aria-hidden="true" />
                        Log out
                    </button>
                </div>

                <p
                    class="flex items-center justify-between rounded-b border-t border-t-rule bg-paper-sunk px-3 py-2 text-12 text-ink-2"
                >
                    <span class="eyebrow">Build</span>
                    <span class="numeral">{{ version }}</span>
                </p>
            </template>
        </div>

        <button
            ref="trigger"
            type="button"
            class="flex min-h-row w-full items-center gap-2 rounded px-2 text-13 transition duration-fast ease-product hover:bg-ink-tint"
            :class="open ? 'bg-ink-tint' : ''"
            :aria-expanded="open"
            aria-haspopup="menu"
            :aria-label="collapsed ? `Account: ${name}` : undefined"
            data-testid="account-menu-trigger"
            @click="open ? close() : openMenu()"
            @keydown="onTriggerKeydown"
        >
            <span
                class="flex h-6 w-6 shrink-0 items-center justify-center rounded bg-accent-tint text-12 font-medium text-accent"
                aria-hidden="true"
            >
                <template v-if="initial">{{ initial }}</template>
                <UserRound v-else :size="13" :stroke-width="1.8" />
            </span>
            <span v-if="!collapsed" class="min-w-0 flex-1 text-left">
                <span class="block truncate text-ink">{{ name }}</span>
                <span v-if="role" class="block truncate text-12 text-ink-2">{{ role }}</span>
            </span>
            <ChevronsUpDown
                v-if="!collapsed"
                :size="14"
                :stroke-width="1.8"
                class="shrink-0 text-ink-2"
                aria-hidden="true"
            />
        </button>

        <ConfirmDialog
            :show="confirming"
            :title="`Log out of ${appName}?`"
            confirm-label="Log out"
            cancel-label="Stay"
            tone="accent"
            :loading="loggingOut"
            @close="confirming = false"
            @confirm="logOut"
        >
            <template #icon>
                <LogOut :size="18" :stroke-width="1.8" aria-hidden="true" />
            </template>
            You are signed in as <span class="text-ink">{{ email }}</span
            >. Anything you have typed and not saved on this screen is lost.
        </ConfirmDialog>
    </div>
</template>
