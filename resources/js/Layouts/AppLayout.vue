<script setup lang="ts">
import CommandPalette from '@/Components/CommandPalette.vue';
import Toaster from '@/Components/ui/Toaster.vue';
import { toast } from '@/lib/toast';
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

const page = usePage();
const menuOpen = ref(false);
const sidebarOpen = ref(false);
const collapsed = ref(false);
const paletteOpen = ref(false);

const links = computed(() => {
    if (!page.props.tenant) {
        return [
            { href: route('super-admin.index'), label: 'Tenants', hint: '' },
            { href: route('super-admin.messages'), label: 'Send log', hint: '' },
            { href: route('super-admin.failures'), label: 'Failures', hint: '' },
        ];
    }

    return [
        { href: route('diary.index'), label: 'Diary', hint: 'D' },
        { href: route('bookings.index'), label: 'Bookings', hint: 'B' },
        { href: route('customers.index'), label: 'Customers', hint: 'C' },
        { href: route('waitlist.index'), label: 'Waitlist', hint: 'W' },
        { href: route('services.index'), label: 'Services', hint: 'S' },
        { href: route('staff.index'), label: 'Staff', hint: 'P' },
        { href: route('availability.index'), label: 'Hours', hint: 'H' },
        { href: route('time-off.index'), label: 'Time off', hint: 'O' },
        { href: route('dashboard'), label: 'Overview', hint: 'V' },
        { href: route('settings.edit'), label: 'Settings', hint: ',' },
        { href: route('billing.index'), label: 'Billing', hint: '' },
        { href: route('imports.show'), label: 'Import', hint: '' },
    ];
});

const isCurrent = (href: string) => {
    const path = href.split('?')[0];

    return page.url === path || page.url.startsWith(`${path}?`) || page.url.startsWith(`${path}/`);
};

const logout = () => {
    router.post(route('logout'));
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

    if (!target) {
        return false;
    }

    return ['INPUT', 'TEXTAREA', 'SELECT'].includes(target.tagName) || target.isContentEditable;
};

const onKey = (event: KeyboardEvent) => {
    if ((event.metaKey || event.ctrlKey) && event.key.toLowerCase() === 'k') {
        event.preventDefault();
        paletteOpen.value = true;
        return;
    }

    if (typingInField(event)) {
        return;
    }

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
        const step = diaryQuery.value.view === 'week' ? -7 : -1;
        goDiary(shiftDate(diaryQuery.value.date, step));
        return;
    }

    if (event.key === 'ArrowRight' && onDiary.value) {
        event.preventDefault();
        const step = diaryQuery.value.view === 'week' ? 7 : 1;
        goDiary(shiftDate(diaryQuery.value.date, step));
    }
};

watch(
    () => page.props.toast,
    (message) => {
        if (typeof message === 'string' && message !== '') {
            toast(message);
        }
    },
    { immediate: true },
);

onMounted(() => {
    collapsed.value = window.matchMedia('(max-width: 1024px)').matches;
    window.addEventListener('keydown', onKey);
});

onUnmounted(() => window.removeEventListener('keydown', onKey));
</script>

<template>
    <div class="min-h-screen bg-paper text-ink">
        <div
            v-if="sidebarOpen"
            class="fixed inset-0 z-30 bg-overlay lg:hidden"
            @click="sidebarOpen = false"
        />

        <aside
            class="fixed inset-y-0 left-0 z-40 border-r border-rule bg-white transition-[width,transform] duration ease-product"
            :class="[
                sidebarOpen ? 'translate-x-0' : '-translate-x-full md:translate-x-0',
                collapsed ? 'md:w-sidebar-collapsed' : 'md:w-sidebar',
                'w-sidebar',
            ]"
        >
            <div class="flex h-topbar items-center justify-between border-b border-rule px-3">
                <Link :href="route('diary.index')" class="truncate text-13 font-medium">
                    <span :class="collapsed ? 'md:hidden' : ''">{{ page.props.tenant?.name ?? page.props.appName }}</span>
                    <span class="hidden md:inline" :class="collapsed ? 'md:inline' : 'md:hidden'">{{ (page.props.tenant?.name ?? 'K').slice(0, 1) }}</span>
                </Link>
                <button
                    type="button"
                    class="hidden min-h-tap text-12 text-ink-2 hover:text-ink lg:inline"
                    @click="collapsed = !collapsed"
                >
                    {{ collapsed ? '›' : '‹' }}
                </button>
            </div>
            <nav class="space-y-0.5 p-2">
                <Link
                    v-for="link in links"
                    :key="link.href"
                    :href="link.href"
                    class="flex min-h-tap items-center rounded px-2 text-13 transition duration-fast ease-product hover:bg-paper-sunk"
                    :class="isCurrent(link.href) ? 'bg-paper-sunk text-ink' : 'text-ink-2 hover:text-ink'"
                    :title="link.label"
                    @click="sidebarOpen = false"
                >
                    <span :class="collapsed ? 'md:hidden' : ''">{{ link.label }}</span>
                    <span class="hidden w-full justify-center" :class="collapsed ? 'md:flex' : 'md:hidden'">
                        {{ link.label.slice(0, 1) }}
                    </span>
                </Link>
            </nav>
        </aside>

        <div class="transition-[padding] duration ease-product" :class="collapsed ? 'md:pl-sidebar-collapsed' : 'md:pl-sidebar'">
            <header class="sticky top-0 z-20 flex h-topbar items-center justify-between border-b border-rule bg-white px-4">
                <button type="button" class="min-h-tap text-13 md:hidden" @click="sidebarOpen = true">
                    Menu
                </button>
                <button
                    type="button"
                    class="hidden min-h-tap items-center gap-2 rounded border border-rule px-3 text-13 text-ink-2 hover:text-ink md:flex"
                    @click="paletteOpen = true"
                >
                    Search
                    <kbd class="text-12">⌘K</kbd>
                </button>
                <div class="relative">
                    <button type="button" class="min-h-tap text-13" @click="menuOpen = !menuOpen">
                        {{ page.props.auth.user?.name }}
                    </button>
                    <div
                        v-if="menuOpen"
                        class="appear absolute right-0 mt-1 w-48 rounded border border-rule bg-white py-1"
                    >
                        <Link
                            :href="route('profile.edit')"
                            class="block min-h-tap px-3 py-2 text-13 hover:bg-paper-sunk"
                            @click="menuOpen = false"
                        >
                            Your profile
                        </Link>
                        <button
                            type="button"
                            class="block min-h-tap w-full px-3 py-2 text-left text-13 hover:bg-paper-sunk"
                            @click="logout"
                        >
                            Log out
                        </button>
                    </div>
                </div>
            </header>

            <div
                v-if="page.props.auth.user && !page.props.auth.user.email_verified_at"
                class="border-b border-rule px-4 py-2 text-13"
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
                v-if="page.props.impersonating"
                class="border-b border-rule bg-paper-sunk px-4 py-2 text-13"
            >
                You are impersonating this salon.
                <button type="button" class="underline" @click="router.post(route('impersonation.stop'))">Stop</button>
            </div>
            <div
                v-if="page.props.tenant?.show_trial_banner"
                class="border-b border-rule px-4 py-2 text-13"
            >
                Trial ends in {{ page.props.tenant.trial_days_remaining }} days.
                <Link :href="route('billing.index')" class="underline">Add a card</Link>
            </div>
            <div
                v-if="page.props.tenant?.read_only"
                class="border-b border-rule px-4 py-2 text-13"
            >
                Admin is read-only until billing is up to date. Clients can still book online.
                <Link :href="route('billing.index')" class="underline">Billing</Link>
            </div>

            <main class="px-4 py-6 md:px-8">
                <slot />
            </main>
        </div>

        <CommandPalette :show="paletteOpen" @close="paletteOpen = false" @create="createBooking" />
        <Toaster />
    </div>
</template>
