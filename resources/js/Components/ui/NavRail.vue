<script setup lang="ts">
import KeyHint from '@/Components/ui/KeyHint.vue';
import RailUserMenu from '@/Components/ui/RailUserMenu.vue';
import { Link } from '@inertiajs/vue3';

/**
 * The operator app's nav rail. `public/mockups/dashboard.html` is the target.
 *
 * 148px on `--paper-sunk` with a hairline right border, the mark and wordmark
 * at the top, 13px nav with the active item on a 5.5% ink tint and counts
 * right-aligned in mono, and the user control pinned to the bottom.
 *
 * Three widths:
 *
 *   - **148px** at ≥1024px. The whole thing.
 *   - **56px** between 768 and 1023. Mark, initials, no labels — see `glyph`.
 *   - **A drawer** below 768, which is the 148px version slid in from the left.
 *
 * It lives in the component library rather than inside `AppLayout` because it
 * is made almost entirely of controls, and controls belong in one place. That
 * also means `/dev/components` can draw all three widths side by side, which is
 * where the tablet rail was actually looked at for the first time.
 */
export type NavLink = {
    href: string;
    label: string;
    /**
     * What the 56px rail shows instead of the label.
     *
     * Chosen per item rather than derived. The first version took the first
     * letter, and rendering the tablet width for the first time made the
     * problem obvious immediately: **Services, Staff and Settings are all "S"**,
     * which is three items the rail cannot tell apart in the one mode where the
     * label is gone. Two letters, picked so no two collide.
     */
    glyph?: string;
    /** Right-aligned, mono. Omit for an item where a number would mean nothing. */
    count?: number | null;
};

withDefaults(
    defineProps<{
        links: NavLink[];
        /** Predicate rather than a value: the caller owns what "here" means. */
        isCurrent: (href: string) => boolean;
        homeHref: string;
        userName: string;
        profileHref: string;
        billingHref?: string;
        logoutHref: string;
        /** 56px icon rail. Ignored while the drawer is open. */
        collapsed?: boolean;
        drawerOpen?: boolean;
        impersonating?: boolean;
        impersonatedTenant?: string | null;
        stopImpersonatingHref?: string;
    }>(),
    { collapsed: false, drawerOpen: false, impersonating: false },
);

const emit = defineEmits<{ navigate: []; search: [] }>();

/**
 * The icon rail's stand-in for a label.
 *
 * Not an icon: this product has no icon set, and eleven invented pictograms
 * would be eleven guesses about what "Time off" looks like. Two letters are
 * unambiguous, they inherit the type system, and the full label is still in
 * `aria-label` and in the tooltip.
 *
 * `glyph` on the link wins; the fallback is the first two letters, which is
 * only ever right by accident — see the type above.
 */
const glyphFor = (link: NavLink) => link.glyph ?? link.label.trim().slice(0, 2);
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
        <!--
            The mark, and the wordmark on two lines.

            "Appoint Manager" is fifteen characters. Inside a 148px rail with
            `px-4`, a 20px mark and an 8px gap there are 88px left, and the name
            set on one line at 13px measures ~96px — it truncates to "Appoint
            Manage…", which is worse than every alternative. Stacking it keeps
            the whole name and keeps 13px, which is on the type scale; shrinking
            to 12px would have made the product's own name the smallest text on
            the screen. See DECISIONS.md for the options that were rejected.
        -->
        <Link :href="homeHref" class="flex items-center gap-2 px-4 py-4 text-ink" aria-label="Appoint Manager">
            <svg width="20" height="20" viewBox="0 0 24 24" aria-hidden="true" class="shrink-0">
                <path fill="currentColor" d="M0 0h16.66L4.66 24H0Z" />
                <path fill="currentColor" d="M19.34 0H24v24H7.34Z" />
            </svg>
            <span
                class="text-13 font-medium leading-none"
                :class="collapsed && !drawerOpen ? 'md:hidden' : ''"
                aria-hidden="true"
            >
                Appoint<br />Manager
            </span>
        </Link>

        <nav class="mt-2 flex-1 overflow-y-auto px-2" aria-label="Main">
            <ul class="space-y-1">
                <li v-for="link in links" :key="link.href">
                    <Link
                        :href="link.href"
                        class="flex min-h-row items-center justify-between gap-2 rounded px-2 text-13 text-ink transition duration-fast ease-product hover:bg-ink-tint"
                        :class="isCurrent(link.href) ? 'bg-ink-tint font-medium' : ''"
                        :aria-current="isCurrent(link.href) ? 'page' : undefined"
                        :title="collapsed && !drawerOpen ? link.label : undefined"
                        :aria-label="collapsed && !drawerOpen ? link.label : undefined"
                        @click="emit('navigate')"
                    >
                        <span :class="collapsed && !drawerOpen ? 'md:hidden' : ''">{{ link.label }}</span>
                        <span
                            v-if="collapsed && !drawerOpen"
                            class="hidden w-full justify-center font-medium md:flex"
                            aria-hidden="true"
                            >{{ glyphFor(link) }}</span
                        >
                        <span
                            v-if="link.count !== undefined && link.count !== null"
                            class="numeral shrink-0 text-12 text-ink-2"
                            :class="collapsed && !drawerOpen ? 'md:hidden' : ''"
                            >{{ link.count }}</span
                        >
                    </Link>
                </li>
            </ul>

            <!-- Search moved out of the old top bar and into the rail. A 56px
                 strip carrying one search button was 56px of chrome saying
                 nothing; here it costs one row. -->
            <button
                type="button"
                class="mt-2 flex min-h-row w-full items-center justify-between gap-2 rounded px-2 text-13 text-ink-2 transition duration-fast ease-product hover:bg-ink-tint hover:text-ink"
                :aria-label="collapsed && !drawerOpen ? 'Search' : undefined"
                @click="emit('search')"
            >
                <span :class="collapsed && !drawerOpen ? 'md:hidden' : ''">Search</span>
                <span v-if="collapsed && !drawerOpen" class="hidden w-full justify-center md:flex" aria-hidden="true">/</span>
                <KeyHint :keys="['⌘', 'K']" :class="collapsed && !drawerOpen ? 'md:hidden' : ''" />
            </button>
        </nav>

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
    </aside>
</template>
