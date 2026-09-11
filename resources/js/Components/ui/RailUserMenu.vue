<script setup lang="ts">
import { useTheme } from '@/composables/useTheme';
import { Link, router, usePage } from '@inertiajs/vue3';
import Check from 'lucide-vue-next/dist/esm/icons/check';
import Monitor from 'lucide-vue-next/dist/esm/icons/monitor';
import Moon from 'lucide-vue-next/dist/esm/icons/moon';
import Sun from 'lucide-vue-next/dist/esm/icons/sun';
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import type { ThemePreference } from '@/lib/theme';

withDefaults(
    defineProps<{
        name: string;
        profileHref: string;
        billingHref?: string;
        logoutHref: string;
        collapsed?: boolean;
        impersonating?: boolean;
        impersonatedTenant?: string | null;
        stopImpersonatingHref?: string;
    }>(),
    { collapsed: false, impersonating: false },
);

const { preference, setPreference } = useTheme();
const showAppearance = computed(() => Boolean(usePage().props.tenant));

const APPEARANCE: Array<{ value: ThemePreference; label: string; icon: typeof Sun }> = [
    { value: 'light', label: 'Light', icon: Sun },
    { value: 'system', label: 'System', icon: Monitor },
    { value: 'dark', label: 'Dark', icon: Moon },
];

const preferenceIcon = computed(
    () => APPEARANCE.find((option) => option.value === preference.value)?.icon ?? Monitor,
);

const open = ref(false);
const root = ref<HTMLElement | null>(null);
const trigger = ref<HTMLButtonElement | null>(null);
let closeTimer: ReturnType<typeof setTimeout> | undefined;

const items = () =>
    Array.from(root.value?.querySelectorAll<HTMLElement>('[role="menuitem"], [role="menuitemradio"]') ?? []);

const close = (restoreFocus = true) => {
    if (closeTimer) {
        clearTimeout(closeTimer);
        closeTimer = undefined;
    }
    if (!open.value) return;
    open.value = false;
    if (restoreFocus) trigger.value?.focus();
};

const chooseAppearance = (next: ThemePreference) => {
    setPreference(next);
    if (closeTimer) clearTimeout(closeTimer);
    closeTimer = setTimeout(() => close(false), 180);
};

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
onBeforeUnmount(() => {
    if (closeTimer) clearTimeout(closeTimer);
    document.removeEventListener('mousedown', onOutside);
});
</script>

<template>
    <div ref="root" class="relative" @keydown="onKeydown">
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
                <div v-if="showAppearance" role="group" aria-label="Appearance">
                    <p class="px-2 pb-1 pt-1 text-12 text-ink-2">Appearance</p>
                    <button
                        v-for="option in APPEARANCE"
                        :key="option.value"
                        type="button"
                        role="menuitemradio"
                        :aria-checked="preference === option.value"
                        class="flex min-h-row w-full items-center gap-2 rounded px-2 py-1 text-left text-13 text-ink transition duration-fast ease-product hover:bg-paper-sunk"
                        @click="chooseAppearance(option.value)"
                    >
                        <component
                            :is="option.icon"
                            :size="15"
                            :stroke-width="1.8"
                            class="shrink-0"
                            aria-hidden="true"
                        />
                        <span class="flex-1 truncate">{{ option.label }}</span>
                        <Check
                            :size="15"
                            :stroke-width="1.8"
                            class="shrink-0 text-accent"
                            :class="preference === option.value ? '' : 'invisible'"
                            aria-hidden="true"
                        />
                    </button>
                </div>
                <hr v-if="showAppearance" role="separator" class="my-2" />
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
                <component
                    :is="preferenceIcon"
                    v-if="showAppearance && !collapsed"
                    :size="15"
                    :stroke-width="1.8"
                    class="ml-auto shrink-0 text-ink-2"
                    aria-hidden="true"
                />
            </button>
        </template>
    </div>
</template>
