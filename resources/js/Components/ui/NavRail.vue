<script setup lang="ts">
import AppLogo from '@/Components/AppLogo.vue';
import KeyHint from '@/Components/ui/KeyHint.vue';
import RailUserMenu from '@/Components/ui/RailUserMenu.vue';
import { navIconFor } from '@/lib/navIcons';
import { Link, usePage } from '@inertiajs/vue3';
import Search from 'lucide-vue-next/dist/esm/icons/search';
import { computed } from 'vue';

/**
 * The operator app's nav rail. `public/mockups/dashboard.html` is the target.
 *
 * 148px on `--paper-sunk` with a hairline right border, the mark and wordmark
 * at the top, 13px nav with the active item on a 5.5% ink tint and counts
 * right-aligned in mono, and the user control pinned to the bottom.
 *
 * Three widths:
 *
 *   - **148px** at ≥1024px. The whole thing, and **text only**: the mockup draws
 *     it that way, the label is already the fastest thing on screen to read,
 *     and an icon beside a word it duplicates is decoration with a width.
 *   - **56px** between 768 and 1023. The mark, and one lucide icon per item —
 *     see `lib/navIcons`. The label is gone at this width and something has to
 *     stand in for it.
 *   - **A drawer** below 768, which is the 148px version slid in from the left,
 *     text and all.
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
 * An icon where the map names one, and two letters where it does not.
 *
 * The letters came first, because this product had no icon set and eleven
 * invented pictograms would have been eleven guesses about what "Time off"
 * looks like. There is a set now, so the guessing is somebody else's, and at
 * 56px an icon beats two letters: a plane is recognised, `To` has to be read.
 *
 * The fallback stays for anything `lib/navIcons` does not name — a rail item
 * with no icon gets its glyph, never an empty box.
 */
/*
 * The home link's accessible name. `AppLogo` inside it is given `label=""`, so
 * the name has to be on the link — otherwise the first thing a screen reader
 * meets in the rail is an unlabelled link.
 */
const appName = computed(() => (usePage().props.appName as string) ?? '');

const glyphFor = (link: NavLink) => link.glyph ?? link.label.trim().slice(0, 2);

const iconFor = (link: NavLink) => navIconFor(link.label);
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
            The lockup, from `AppLogo` — which owns all four of its files. This
            block used to hand-roll its own SVG and its own two-line span, which
            meant the product name was drawn by two files that could disagree.

            **The stack is gone with the type that forced it.** The wordmark was
            live text, and "Appoint Manager" set 104.63px at 13px/500 inside the
            88px this rail leaves — so it went to two lines. The lockup is
            artwork now: 260x64, on one line, at any name length. `AppLogo`
            records the rest.

            **40px, and the rail is sized from it rather than the other way
            round.** The mark was 20px — the size a favicon is — and read as a
            label for the rail rather than as the way home. At 40px the lockup
            is 163px wide, which is why `--rail` is 192px and not the 148px it
            was; the token moved and `AppLayout`'s `md:pl-rail` moved with it.

            Collapsed is the tight one: 40px square inside 56px leaves 8px a
            side, so the link drops its padding and centres there instead of
            keeping `px-3` and pushing the mark 24px past the edge. `py-4` is
            unconditional and the block has no fixed height, so the row is
            72px tall now and the mark is centred in it either way.

            `label=""` because the link is already named by `aria-label` — an
            alt text here would make a screen reader read the product twice.
        -->
        <Link
            :href="homeHref"
            class="flex items-center py-4"
            :class="collapsed && !drawerOpen ? 'px-3 md:justify-center md:px-0' : 'px-3'"
            :aria-label="appName"
        >
            <AppLogo :size="40" label="" :variant="collapsed && !drawerOpen ? 'mark' : 'lockup'" />
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
                        <!--
                            Decorative, always. The accessible name comes from
                            the `aria-label` on the link, and `title` carries the
                            same words for a pointer — an icon-only control needs
                            both, and neither is the icon's job.
                        -->
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
                <span v-if="collapsed && !drawerOpen" class="hidden w-full justify-center md:flex" aria-hidden="true">
                    <Search :size="18" :stroke-width="1.75" />
                </span>
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
