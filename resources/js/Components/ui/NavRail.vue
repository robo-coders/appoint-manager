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
 * On `--paper-sunk` with a hairline right border, the mark and wordmark at the
 * top, 13px nav in three named groups, counts right-aligned in mono, and the
 * user control pinned to the bottom.
 *
 * **The active item is a 2px accent bar and an accent wash**, not the 5.5% ink
 * tint it was. An ink tint on a sunk rail is a tonal step away from a tonal
 * step: it was there, it was findable if you looked, and "where am I" is not a
 * question anybody should have to look to answer. The bar is on the border box
 * of every item, transparent when the item is not current, so nothing shifts by
 * two pixels as she moves between screens.
 *
 * That is the rail's one use of the accent and it is the same meaning on every
 * screen — you are here — which is what the ration in DESIGN.md is about.
 *
 * The bar is half of it. The other half is that the items around it stepped
 * back: every label in the rail was full ink at 400, so the current one had a
 * mark on it and no more weight than the eleven it sits among. Secondary ink for
 * the rest, ink and 500 for the current one, which is the same two-step the
 * status pills and the filter strip use — twelve items you aim at rather than
 * twelve you read.
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
     * The heading this item sits under.
     *
     * Twelve items in one column is a list you read from the top every time.
     * Three groups of four is a list you *aim* at: the day's work, the things
     * that were set up once, and the account. The rail groups consecutive items
     * carrying the same value, so the order the caller declares is the order on
     * screen and a group cannot be split in two by accident.
     *
     * Omitted means ungrouped, and an ungrouped run draws no heading at all —
     * the console has four items and does not need to be told they are four
     * items.
     */
    group?: string;
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

const props = withDefaults(
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

/**
 * Consecutive runs, not a `groupBy`.
 *
 * A `groupBy` would silently gather two items declared eight apart into one
 * block, which turns a typo in the caller's list into a reordered nav that looks
 * deliberate. A run breaks where the caller broke it.
 */
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
            <!--
                One heading per group, in small caps. Not `uppercase`: the system
                bans ALL CAPS and means it, and a small cap sits on the x-height,
                so a heading reads as a rule with words on it rather than as a
                second thing competing with the item under it. `.eyebrow` in
                base.css owns the treatment.

                The heading is hidden at 56px along with every other word in the
                rail. A group label with no group visible under it is a stray
                fragment, and the icons are already grouped by the gap.
            -->
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
                        <!--
                            **The bar is inset, and it is an element rather than
                            the item's left border.**

                            As `border-l-2` it ran the full height of the item and
                            sat flush against the item above and below, so four
                            consecutive items produced one unbroken 2px column
                            down the rail with a coloured segment in it — a rule
                            with a highlight, not a marker on a row. The redesign
                            draws it 7px in from the top and bottom, rounded on
                            the outer edge, hanging in the nav's own left padding
                            rather than inside the item's box.

                            `relative` on the link and `absolute` on the bar is
                            what keeps the label from moving: the marker is out of
                            flow, so a current item and a non-current one are the
                            same width to the pixel and nothing shifts as she
                            moves between screens. That was the whole reason the
                            border version was transparent-when-inactive, and this
                            gets it for free.
                        -->
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
                            <!--
                                Decorative, always. The accessible name comes
                                from the `aria-label` on the link, and `title`
                                carries the same words for a pointer — an
                                icon-only control needs both, and neither is the
                                icon's job.
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
            </div>
        </nav>

        <!--
            Search and the account, in one block pinned to the bottom behind a
            rule. Search was the last item *of the nav*, four pixels under
            "Settings" and inside the same scroll — so on a short window it
            scrolled away, and on a tall one it read as a thirteenth destination
            rather than as a tool. The redesign puts it with the account control
            below the rule, which is what a rule is for: these two are not places
            in the product.
        -->
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
