<script setup lang="ts">
import Banner from '@/Components/ui/Banner.vue';
import BetaSandboxBanner from '@/Components/BetaSandbox/Banner.vue';
import CommandPalette from '@/Components/ui/CommandPalette.vue';
import MobileTabBar from '@/Components/ui/MobileTabBar.vue';
import NavRail from '@/Components/ui/NavRail.vue';
import ToastContainer from '@/Components/ui/ToastContainer.vue';
import { configureToasts } from '@/lib/toast';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * The operator shell. `public/mockups/dashboard.html` is the target.
 *
 * What changed, and why the previous one was wrong:
 *
 *   - **148px, on `--paper-sunk`, with a hairline right border.** It was a
 *     ~250px white rail, which read as a second page rather than as chrome.
 *   - **The user control is pinned to the bottom of the rail**, not floating
 *     top-right in a top bar. It opens *upward*.
 *   - **Counts, right-aligned in mono.** They come from `navCounts` on the
 *     shared props and they are the reason to look at the rail at all.
 *   - **No top bar on desktop.** The mockup has none: the page owns its own
 *     heading, and a 56px strip carrying one search button was 56px of chrome
 *     that said nothing. Search moved into the rail.
 *
 * The rail itself is `ui/NavRail`, so the gallery can draw all three of its
 * widths at once and so every control in it lives in the library. The wordmark
 * decision is documented there, next to the markup that implements it.
 */

const page = usePage();

const DEFAULT_MOBILE_BREAKPOINT = 768;
const DEFAULT_RAIL_CEILING = 1023;

const collapsed = ref(false);
const paletteOpen = ref(false);

type NavLink = { href: string; label: string; glyph: string; hint: string; group?: string; count?: number | null };

const counts = computed(() => (page.props.navCounts as Record<string, number> | null) ?? null);

/**
 * Which session this shell is signing out of.
 *
 * The rail hard-coded `route('logout')`, which is the *app* surface's route.
 * The console runs the same shell on a different host with a different session
 * cookie and a different route — `admin.logout` — so the one control that ends
 * a super admin session pointed at a route that does not exist on the surface
 * it was rendered on. DECISIONS.md recorded it as "the console has no logout
 * control"; it had one, aimed at the wrong door.
 *
 * The console is the tenant-less shell, which is the same condition the rail's
 * own link list already branches on.
 */
const logoutHref = computed(() => (page.props.tenant ? route('logout') : route('admin.logout')));

const links = computed<NavLink[]>(() => {
    if (!page.props.tenant) {
        return [
            { href: route('super-admin.index'), label: 'Tenants', glyph: 'Te', hint: '' },
            { href: route('super-admin.messages'), label: 'Send log', glyph: 'Sl', hint: '' },
            { href: route('super-admin.failures'), label: 'Failures', glyph: 'Fa', hint: '' },
            { href: route('super-admin.verticals'), label: 'Verticals', glyph: 'Ve', hint: '' },
        ];
    }

    const n = counts.value;

    /*
     * Three groups, in the order a week is worked rather than alphabetically.
     *
     * Day-to-day is what she opens the app for. Setup is what was decided once
     * and is changed a few times a year. Account is the shop rather than the
     * diary. Twelve flat items had the diary and the SMS settings sitting the
     * same distance from her eye, which is not what they are worth.
     *
     * `glyph` is the 56px rail's label. Services / Staff / Settings all start
     * with S, so these are chosen rather than derived.
     */
    const dayToDay = 'Day-to-day';
    const setup = 'Setup';
    const account = 'Account';

    return [
        { href: route('diary.index'), label: 'Diary', glyph: 'Di', hint: 'D', group: dayToDay },
        { href: route('bookings.index'), label: 'Bookings', glyph: 'Bk', hint: 'B', group: dayToDay, count: n?.bookings },
        /*
         * Waitlist and Overdue before Customers, which is the redesign's order
         * and the order a day is worked: the two lists that are *owed something
         * today* sit under the two you live in, and the directory of everyone
         * who has ever booked comes last. It had Customers third, so the two
         * queues somebody is meant to clear were the bottom of the group.
         */
        { href: route('waitlist.index'), label: 'Waitlist', glyph: 'Wl', hint: 'W', group: dayToDay, count: n?.waitlist },
        { href: route('overdue.index'), label: 'Overdue', glyph: 'Od', hint: 'U', group: dayToDay, count: n?.overdue },
        { href: route('customers.index'), label: 'Customers', glyph: 'Cu', hint: 'C', group: dayToDay, count: n?.customers },
        { href: route('services.index'), label: 'Services', glyph: 'Sv', hint: 'S', group: setup, count: n?.services },
        { href: route('staff.index'), label: 'Staff', glyph: 'St', hint: 'P', group: setup, count: n?.staff },
        { href: route('availability.index'), label: 'Hours', glyph: 'Hr', hint: 'H', group: setup },
        { href: route('time-off.index'), label: 'Time off', glyph: 'To', hint: 'O', group: setup },
        { href: route('dashboard'), label: 'Overview', glyph: 'Ov', hint: 'V', group: account },
        { href: route('imports.show'), label: 'Import', glyph: 'Im', hint: '', group: account },
        { href: route('settings.edit'), label: 'Settings', glyph: 'Se', hint: ',', group: account },
    ];
});

/**
 * Is the rail's link the page we are on?
 *
 * `route()` used to return an **absolute** URL and `page.url` is a path, so the
 * previous version compared "http://app.example/dashboard" with "/dashboard"
 * and was false for every item on every screen — the active tint has never
 * appeared. Both sides are reduced to a path first. Same-surface `route()` is
 * relative now (see `lib/ziggyHost.ts`); this still has to handle a full URL,
 * because a stub or a leftover absolute href must not un-highlight the rail.
 */
const pathOf = (url: string) => {
    try {
        return new URL(url, window.location.origin).pathname;
    } catch {
        return url.split('?')[0];
    }
};

const isCurrent = (href: string) => {
    const path = pathOf(href);
    const here = pathOf(page.url);

    return here === path || here.startsWith(`${path}/`);
};

const onDiary = computed(() => page.url.startsWith('/diary'));

const diaryQuery = computed(() => {
    const query = page.url.includes('?') ? page.url.slice(page.url.indexOf('?') + 1) : '';
    const params = new URLSearchParams(query);

    return {
        date: params.get('date') ?? page.props.today ?? '',
        view: params.get('view') === 'week' ? 'week' : 'day',
    };
});

const shiftDate = (value: string, amount: number) => {
    const [year, month, day] = value.split('-').map(Number);
    const next = new Date(year, month - 1, day + amount);

    return [
        next.getFullYear(),
        String(next.getMonth() + 1).padStart(2, '0'),
        String(next.getDate()).padStart(2, '0'),
    ].join('-');
};

const goDiary = (date: string, view = diaryQuery.value.view) => {
    router.get(route('diary.index'), { date, view }, { preserveState: true, preserveScroll: true });
};

const createBooking = () => {
    router.get(
        route('diary.index'),
        { date: diaryQuery.value.date || page.props.today, view: diaryQuery.value.view, new: 1 },
        { preserveState: true, preserveScroll: true },
    );
};

const typingInField = (event: KeyboardEvent) => {
    const target = event.target as HTMLElement | null;

    if (!target) return false;

    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable;
};

const onKey = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        paletteOpen.value = true;

        return;
    }

    if (typingInField(event)) return;

    if (event.key === '/') {
        event.preventDefault();
        paletteOpen.value = true;

        return;
    }

    if (event.key === 'n') {
        event.preventDefault();
        createBooking();

        return;
    }

    if (event.key === 't') {
        event.preventDefault();
        goDiary(page.props.today ?? diaryQuery.value.date, diaryQuery.value.view);

        return;
    }

    if (event.key === 'ArrowLeft' && onDiary.value) {
        event.preventDefault();
        goDiary(shiftDate(diaryQuery.value.date, diaryQuery.value.view === 'week' ? -7 : -1));

        return;
    }

    if (event.key === 'ArrowRight' && onDiary.value) {
        event.preventDefault();
        goDiary(shiftDate(diaryQuery.value.date, diaryQuery.value.view === 'week' ? 7 : 1));
    }
};

/*
 * Two rail widths, one media query. The rail collapses to 56px across the
 * tablet band — where 148px of chrome is 15% of the viewport — and is replaced
 * altogether by `ui/MobileTabBar` below it. The band's bounds come from
 * `config/ui.php` on the shared props, so the numbers here and Tailwind's `md`
 * cannot drift apart.
 */
let media: MediaQueryList | undefined;
const onMediaChange = (event: MediaQueryListEvent | MediaQueryList) => (collapsed.value = event.matches);

onMounted(() => {
    const ui = page.props.ui as
        | { mobile_breakpoint: number; rail_collapsed_ceiling: number; toast_duration_ms?: number }
        | undefined;
    const from = ui?.mobile_breakpoint ?? DEFAULT_MOBILE_BREAKPOINT;
    const to = ui?.rail_collapsed_ceiling ?? DEFAULT_RAIL_CEILING;

    configureToasts(ui?.toast_duration_ms);

    media = window.matchMedia(`(min-width: ${from}px) and (max-width: ${to}px)`);
    onMediaChange(media);
    media.addEventListener('change', onMediaChange);
    window.addEventListener('keydown', onKey);
});

onUnmounted(() => {
    media?.removeEventListener('change', onMediaChange);
    window.removeEventListener('keydown', onKey);
});
</script>

<template>
    <div class="min-h-screen bg-paper text-ink">
        <div class="hidden md:block">
            <NavRail
                :links="links"
                :is-current="isCurrent"
                :home-href="page.props.tenant ? route('diary.index') : route('super-admin.index')"
                :user-name="page.props.auth.user?.name ?? ''"
                :profile-href="route('profile.edit')"
                :billing-href="page.props.tenant ? route('settings.billing') : undefined"
                :logout-href="logoutHref"
                :collapsed="collapsed"
                :drawer-open="false"
                :impersonating="page.props.impersonating"
                :impersonated-tenant="(page.props.impersonatedTenant as string | null) ?? null"
                :stop-impersonating-href="route('impersonation.stop')"
                @search="paletteOpen = true"
            />
        </div>

        <div
            class="transition-[padding] duration ease-product"
            :class="collapsed ? 'md:pl-rail-collapsed' : 'md:pl-rail'"
        >
            <!--
                BetaSandbox — see BETA_SANDBOX.md. First in the notice stack: it
                is the standing fact about this whole installation of the app,
                and the ones below it are transient. It renders itself away for
                every tenant that is not in the beta.
            -->
            <BetaSandboxBanner />

            <Banner
                v-if="page.props.auth.user && !page.props.auth.user.email_verified_at"
                message="Confirm your email so clients can reach you."
                action-label="Resend the email"
                :action-href="route('verification.send')"
                action-method="post"
            />

            <Banner
                v-if="page.props.tenant?.show_trial_banner"
                :message="`Trial ends in ${page.props.tenant.trial_days_remaining} days.`"
                action-label="Add a card"
                :action-href="route('settings.billing')"
            />

            <Banner
                v-if="page.props.tenant?.read_only"
                message="Admin is read-only until billing is up to date. Clients can still book online."
                action-label="Billing"
                :action-href="route('settings.billing')"
            />

            <Banner
                v-if="page.props.sms?.stopped === 'killed'"
                message="SMS is switched off for this salon. Email still goes out, and you can still ring people."
            />
            <Banner
                v-else-if="page.props.sms?.stopped === 'ceiling'"
                message="SMS has reached this salon's send limit. Email still goes out. The overdue list still works."
            />
            <Banner
                v-else-if="page.props.sms?.stopped === 'allowance'"
                message="This cycle’s texts are used up. Email still goes out."
                :action-label="`Buy ${page.props.sms.topup_size} more for ${page.props.sms.topup_price}`"
                :action-href="route('billing.index')"
            />
            <Banner
                v-else-if="page.props.sms?.warning === 80"
                :message="`You have used ${page.props.sms.used} of ${page.props.sms.included} texts this cycle.`"
                action-label="Billing"
                :action-href="route('billing.index')"
            />

            <main class="under-tabbar px-4 pt-6 md:px-8">
                <slot />
            </main>
        </div>

        <MobileTabBar
            v-if="page.props.tenant"
            :links="links"
            :user-name="page.props.auth.user?.name ?? ''"
            :profile-href="route('profile.edit')"
            :billing-href="route('settings.billing')"
            :logout-href="logoutHref"
            @search="paletteOpen = true"
        />

        <CommandPalette :show="paletteOpen" @close="paletteOpen = false" @create="createBooking" />
        <ToastContainer />
    </div>
</template>
