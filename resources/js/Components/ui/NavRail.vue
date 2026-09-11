<script setup lang="ts">
import AppLogo from '@/Components/AppLogo.vue';
import KeyHint from '@/Components/ui/KeyHint.vue';
import RailUserMenu from '@/Components/ui/RailUserMenu.vue';
import { navIconFor } from '@/lib/navIcons';
import { Link, usePage } from '@inertiajs/vue3';
import Search from 'lucide-vue-next/dist/esm/icons/search';
import { computed } from 'vue';

export type NavLink = {
    href: string;
    label: string;
    group?: string;
    glyph?: string;
    count?: number | null;
};

const props = withDefaults(
    defineProps<{
        links: NavLink[];
        isCurrent: (href: string) => boolean;
        homeHref: string;
        userName: string;
        profileHref: string;
        billingHref?: string;
        logoutHref: string;
        collapsed?: boolean;
        drawerOpen?: boolean;
        impersonating?: boolean;
        impersonatedTenant?: string | null;
        stopImpersonatingHref?: string;
    }>(),
    { collapsed: false, drawerOpen: false, impersonating: false },
);

const emit = defineEmits<{ navigate: []; search: [] }>();

const appName = computed(() => (usePage().props.appName as string) ?? '');

const glyphFor = (link: NavLink) => link.glyph ?? link.label.trim().slice(0, 2);

const iconFor = (link: NavLink) => navIconFor(link.label);

const groups = computed(() => {
    const out: Array<{ label: string | null; items: NavLink[] }> = [];

    for (const link of props.links) {
        const label = link.group ?? null;
        const last = out[out.length - 1];

        if (last && last.label === label) {
            last.items.push(link);
            continue;
        }

        out.push({ label, items: [link] });
    }

    return out;
});
</script>

<template>
    <aside
        class="fixed inset-y-0 left-0 z-40 flex flex-col border-r border-r-rule bg-paper-sunk transition-[width,transform] duration ease-product"
        :class="[
            drawerOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
            collapsed && !drawerOpen ? 'w-rail md:w-rail-collapsed' : 'w-rail',
        ]"
        aria-label="Sidebar"
    >
        <Link
            :href="homeHref"
            class="flex items-center py-4"
            :class="collapsed && !drawerOpen ? 'px-3 md:justify-center md:px-0' : 'px-3'"
            :aria-label="appName"
        >
            <AppLogo :size="40" label="" :variant="collapsed && !drawerOpen ? 'mark' : 'lockup'" />
        </Link>

        <nav class="mt-2 flex-1 overflow-y-auto px-2" aria-label="Main">
            <div v-for="(group, index) in groups" :key="group.label ?? index" :class="index > 0 ? 'mt-4' : ''">
                <p
                    v-if="group.label"
                    class="eyebrow px-2 pb-1"
                    :class="collapsed && !drawerOpen ? 'md:hidden' : ''"
                >
                    {{ group.label }}
                </p>
                <ul>
                    <li v-for="link in group.items" :key="link.href">
                        <Link
                            :href="link.href"
                            class="relative flex min-h-row items-center justify-between gap-2 rounded px-2 text-13 transition duration-fast ease-product hover:bg-ink-tint hover:text-ink"
                            :class="isCurrent(link.href) ? 'bg-accent-tint font-medium text-ink' : 'text-ink-2'"
                            :aria-current="isCurrent(link.href) ? 'page' : undefined"
                            :title="collapsed && !drawerOpen ? link.label : undefined"
                            :aria-label="collapsed && !drawerOpen ? link.label : undefined"
                            @click="emit('navigate')"
                        >
                            <span
                                v-if="isCurrent(link.href)"
                                aria-hidden="true"
                                class="absolute -left-2 bottom-1 top-1 w-0.5 rounded bg-accent"
                            ></span>
                            <span :class="collapsed && !drawerOpen ? 'md:hidden' : ''">{{ link.label }}</span>
                            <span
                                v-if="collapsed && !drawerOpen"
                                class="hidden w-full justify-center font-medium md:flex"
                                aria-hidden="true"
                            >
                                <component :is="iconFor(link)" v-if="iconFor(link)" :size="18" :stroke-width="1.75" />
                                <template v-else>{{ glyphFor(link) }}</template>
                            </span>
                            <span
                                v-if="link.count !== undefined && link.count !== null"
                                class="numeral shrink-0 text-12 text-ink-2"
                                :class="collapsed && !drawerOpen ? 'md:hidden' : ''"
                                >{{ link.count }}</span
                            >
                        </Link>
                    </li>
                </ul>
            </div>
        </nav>

        <div class="border-t border-t-rule p-2">
            <button
                type="button"
                class="flex min-h-row w-full items-center justify-between gap-2 rounded px-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-ink-tint hover:text-ink"
                :class="collapsed && !drawerOpen ? 'md:justify-center' : ''"
                :aria-label="collapsed && !drawerOpen ? 'Search' : undefined"
                @click="emit('search')"
            >
                <span :class="collapsed && !drawerOpen ? 'md:hidden' : ''">Search</span>
                <span v-if="collapsed && !drawerOpen" class="hidden w-full justify-center md:flex" aria-hidden="true">
                    <Search :size="18" :stroke-width="1.75" />
                </span>
                <KeyHint :keys="['⌘K']" :class="collapsed && !drawerOpen ? 'md:hidden' : ''" />
            </button>

            <RailUserMenu
                :name="userName"
                :profile-href="profileHref"
                :billing-href="billingHref"
                :logout-href="logoutHref"
                :collapsed="collapsed && !drawerOpen"
                :impersonating="impersonating"
                :impersonated-tenant="impersonatedTenant"
                :stop-impersonating-href="stopImpersonatingHref"
            />
        </div>
    </aside>
</template>
