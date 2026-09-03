<script setup lang="ts">
import CommandPalette from '@/Components/ui/CommandPalette.vue';
import Button from '@/Components/ui/Button.vue';
import NavRail from '@/Components/ui/NavRail.vue';
import Toaster from '@/Components/ui/Toaster.vue';
import { toast } from '@/lib/toast';
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

/** `full` at desktop, `icons` at tablet, `drawer` on a phone. */
const drawerOpen = ref(false);
const collapsed = ref(false);
const paletteOpen = ref(false);

type NavLink = { href: string; label: string; glyph: string; hint: string; count?: number | null };

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

    return [
        // `glyph` is the 56px rail's label. Services / Staff / Settings all
        // start with S, so these are chosen rather than derived.
        { href: route('diary.index'), label: 'Diary', glyph: 'Di', hint: 'D' },
        { href: route('bookings.index'), label: 'Bookings', glyph: 'Bk', hint: 'B', count: n?.bookings },
        { href: route('customers.index'), label: 'Customers', glyph: 'Cu', hint: 'C', count: n?.customers },
        { href: route('waitlist.index'), label: 'Waitlist', glyph: 'Wl', hint: 'W', count: n?.waitlist },
        { href: route('overdue.index'), label: 'Overdue', glyph: 'Od', hint: 'U', count: n?.overdue },
        { href: route('services.index'), label: 'Services', glyph: 'Sv', hint: 'S', count: n?.services },
        { href: route('staff.index'), label: 'Staff', glyph: 'St', hint: 'P', count: n?.staff },
        { href: route('availability.index'), label: 'Hours', glyph: 'Hr', hint: 'H' },
        { href: route('time-off.index'), label: 'Time off', glyph: 'To', hint: 'O' },
        { href: route('dashboard'), label: 'Overview', glyph: 'Ov', hint: 'V' },
        { href: route('imports.show'), label: 'Import', glyph: 'Im', hint: '' },
        { href: route('settings.edit'), label: 'Settings', glyph: 'Se', hint: ',' },
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

watch(
    () => page.props.toast,
    (message) => {
        if (typeof message === 'string' && message !== '') toast(message);
    },
    { immediate: true },
);

/*
 * Three widths, one media query each way. The rail collapses to 56px between
 * 768 and 1023 — a tablet, where 148px of chrome is 15% of the viewport — and
 * becomes a drawer below 768.
 */
let media: MediaQueryList | undefined;
const onMediaChange = (event: MediaQueryListEvent | MediaQueryList) => (collapsed.value = event.matches);

onMounted(() => {
    media = window.matchMedia('(min-width: 768px) and (max-width: 1023px)');
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
        <!-- Mobile drawer scrim. -->
        <div v-if="drawerOpen" class="fixed inset-0 z-30 bg-overlay md:hidden" @click="drawerOpen = false" />

        <NavRail
            :links="links"
            :is-current="isCurrent"
            :home-href="page.props.tenant ? route('diary.index') : route('super-admin.index')"
            :user-name="page.props.auth.user?.name ?? ''"
            :profile-href="route('profile.edit')"
            :billing-href="page.props.tenant ? route('billing.index') : undefined"
            :logout-href="logoutHref"
            :collapsed="collapsed"
            :drawer-open="drawerOpen"
            :impersonating="page.props.impersonating"
            :impersonated-tenant="(page.props.impersonatedTenant as string | null) ?? null"
            :stop-impersonating-href="route('impersonation.stop')"
            @navigate="drawerOpen = false"
            @search="
                paletteOpen = true;
                drawerOpen = false;
            "
        />

        <div
            class="transition-[padding] duration ease-product"
            :class="collapsed ? 'md:pl-rail-collapsed' : 'md:pl-rail'"
        >
            <!-- The only top bar left, and it exists only on a phone, where the
                 rail is a drawer that has to be opened from somewhere. -->
            <header class="flex h-topbar items-center border-b border-b-rule bg-white px-4 md:hidden">
                <Button variant="ghost" @click="drawerOpen = true">Menu</Button>
            </header>

            <div
                v-if="page.props.auth.user && !page.props.auth.user.email_verified_at"
                class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
            >
                Confirm your email so clients can reach you.
                <Link
                    :href="route('verification.send')"
                    method="post"
                    as="button"
                    class="underline decoration-rule underline-offset-4"
                >
                    Resend the email
                </Link>
            </div>

            <div
                v-if="page.props.tenant?.show_trial_banner"
                class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
            >
                Trial ends in {{ page.props.tenant.trial_days_remaining }} days.
                <Link :href="route('billing.index')" class="underline decoration-rule underline-offset-4">Add a card</Link>
            </div>

            <div v-if="page.props.tenant?.read_only" class="border-b border-b-rule px-4 py-2 text-13 md:px-8">
                Admin is read-only until billing is up to date. Clients can still book online.
                <Link :href="route('billing.index')" class="underline decoration-rule underline-offset-4">Billing</Link>
            </div>

            <div
                v-if="page.props.sms?.stopped === 'killed'"
                class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
            >
                SMS is switched off for this salon. Email still goes out, and you can still ring people.
            </div>
            <div
                v-else-if="page.props.sms?.stopped === 'ceiling'"
                class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
            >
                SMS has reached this salon's send limit. Email still goes out. The overdue list still works.
            </div>
            <div
                v-else-if="page.props.sms?.stopped === 'allowance'"
                class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
            >
                This cycle’s texts are used up. Email still goes out.
                <Link :href="route('billing.index')" class="underline decoration-rule underline-offset-4">
                    Buy {{ page.props.sms.topup_size }} more for {{ page.props.sms.topup_price }}
                </Link>
            </div>
            <div
                v-else-if="page.props.sms?.warning === 80"
                class="border-b border-b-rule px-4 py-2 text-13 md:px-8"
            >
                You have used {{ page.props.sms.used }} of {{ page.props.sms.included }} texts this cycle.
                <Link :href="route('billing.index')" class="underline decoration-rule underline-offset-4">Billing</Link>
            </div>

            <main class="px-4 py-6 md:px-8">
                <slot />
            </main>
        </div>

        <CommandPalette :show="paletteOpen" @close="paletteOpen = false" @create="createBooking" />
        <Toaster />
    </div>
</template>
