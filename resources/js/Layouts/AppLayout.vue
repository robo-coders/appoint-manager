<script setup lang="ts">
import { bootTheme } from '@/composables/useTheme';
import Badge from '@/Components/ui/Badge.vue';
import Banner from '@/Components/ui/Banner.vue';
import BetaSandboxBanner from '@/Components/BetaSandbox/Banner.vue';
import CommandPalette from '@/Components/ui/CommandPalette.vue';
import MobileTabBar from '@/Components/ui/MobileTabBar.vue';
import NavRail from '@/Components/ui/NavRail.vue';
import ToastContainer from '@/Components/ui/ToastContainer.vue';
import { configureToasts } from '@/lib/toast';
import { Link, router, usePage } from '@inertiajs/vue3';
import ChevronRight from 'lucide-vue-next/dist/esm/icons/chevron-right';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const page = usePage();

if (page.props.tenant) {
    bootTheme(page.props.auth.user?.theme_preference);
}

const DEFAULT_MOBILE_BREAKPOINT = 768;
const DEFAULT_RAIL_CEILING = 1023;

const collapsed = ref(false);
const paletteOpen = ref(false);

type NavLink = { href: string; label: string; glyph: string; hint: string; group?: string; count?: number | null };

const counts = computed(() => (page.props.navCounts as Record<string, number> | null) ?? null);

const logoutHref = computed(() => (page.props.tenant ? route('logout') : route('admin.logout')));

const links = computed<NavLink[]>(() => {
    const n = counts.value;

    if (!page.props.tenant) {
        const platform = 'Platform';

        return [
            { href: route('super-admin.index'), label: 'Tenants', glyph: 'Te', hint: '', group: platform, count: n?.tenants },
            { href: route('super-admin.messages'), label: 'Send log', glyph: 'Sl', hint: '', group: platform },
            { href: route('super-admin.failures'), label: 'Failures', glyph: 'Fa', hint: '', group: platform, count: n?.failures },
            { href: route('super-admin.verticals'), label: 'Verticals', glyph: 'Ve', hint: '', group: platform },
        ];
    }

    const dayToDay = 'Day-to-day';
    const setup = 'Setup';
    const account = 'Account';

    return [
        { href: route('diary.index'), label: 'Diary', glyph: 'Di', hint: 'D', group: dayToDay },
        { href: route('bookings.index'), label: 'Bookings', glyph: 'Bk', hint: 'B', group: dayToDay, count: n?.bookings },
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

const pathOf = (url: string) => {
    try {
        return new URL(url, window.location.origin).pathname;
    } catch {
        return url.split('?')[0];
    }
};

const currentHref = computed(() => {
    const here = pathOf(page.url);

    return (
        links.value
            .filter((link) => {
                const path = pathOf(link.href);

                return here === path || here.startsWith(`${path}/`);
            })
            .sort((a, b) => pathOf(b.href).length - pathOf(a.href).length)[0]?.href ?? null
    );
});

const isCurrent = (href: string) => currentHref.value === href;

const onDiary = computed(() => page.url.startsWith('/diary'));

const isConsole = computed(() => !page.props.tenant);

const crumb = computed(() => {
    const current = links.value.find((link) => isCurrent(link.href));

    return current ? { group: current.group ?? null, label: current.label } : null;
});

const environment = computed(() => (page.props.environment as string | undefined) ?? '');

const appVersion = computed(() => (page.props.appVersion as string | undefined) ?? '');

const dismissedNotices = computed(() => (page.props.dismissedNotices as string[] | undefined) ?? []);

const emailNoticeHrefs = computed(() =>
    page.props.tenant
        ? { resend: route('verification.send'), dismiss: null }
        : { resend: route('admin.verification.send'), dismiss: route('admin.notices.dismiss', 'email-verification') },
);

const showEmailNotice = computed(
    () =>
        Boolean(page.props.auth.user)
        && !page.props.auth.user?.email_verified_at
        && !dismissedNotices.value.includes('email-verification'),
);

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
                :admin="isConsole"
                :user-email="page.props.auth.user?.email ?? ''"
                :user-role="page.props.auth.user?.role ?? null"
                :login-href="route('admin.login')"
                :version="appVersion"
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
            <div
                v-if="isConsole && crumb"
                class="flex items-center gap-2 border-b border-b-rule px-4 py-2 text-13 text-ink-2 md:px-8"
                data-testid="console-breadcrumb"
            >
                <template v-if="crumb.group">
                    <span>{{ crumb.group }}</span>
                    <ChevronRight :size="13" :stroke-width="1.8" class="shrink-0 text-ink-3" aria-hidden="true" />
                </template>
                <span class="text-ink">{{ crumb.label }}</span>
                <Badge v-if="environment" tone="neutral" class="ml-2">{{ environment }}</Badge>
            </div>

            <BetaSandboxBanner />

            <Banner
                v-if="showEmailNotice"
                message="Confirm your email so clients can reach you."
                action-label="Resend the email"
                :action-href="emailNoticeHrefs.resend"
                action-method="post"
                :dismiss-href="emailNoticeHrefs.dismiss"
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
