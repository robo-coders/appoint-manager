<script setup lang="ts">
import type { NavLink } from '@/Components/ui/NavRail.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import { navIconFor } from '@/lib/navIcons';
import { Link, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    links: NavLink[];
    userName?: string;
    profileHref?: string;
    billingHref?: string;
    logoutHref?: string;
}>();

const emit = defineEmits<{ navigate: []; search: [] }>();

export type TabKey = 'Bookings' | 'Waitlist' | 'Overview' | 'Staff';

const TAB_LABELS: TabKey[] = ['Bookings', 'Waitlist', 'Overview', 'Staff'];

const page = usePage();

const sheetOpen = ref(false);

const pathOf = (url: string) => {
    try {
        return new URL(url, window.location.origin).pathname;
    } catch {
        return url.split('?')[0];
    }
};

const here = computed(() => pathOf(page.url));

const matches = (href: string) => {
    const path = pathOf(href);

    return here.value === path || here.value.startsWith(`${path}/`);
};

const tabs = computed(() =>
    TAB_LABELS.map((label) => props.links.find((link) => link.label === label)).filter(
        (link): link is NavLink => link !== undefined,
    ),
);

const overflow = computed(() => props.links.filter((link) => !TAB_LABELS.includes(link.label as TabKey)));

const onATab = computed(() => tabs.value.some((link) => matches(link.href)));

const moreCurrent = computed(() => !onATab.value && overflow.value.some((link) => matches(link.href)));

const iconFor = (link: NavLink) => navIconFor(link.label);

const moreIcon = navIconFor('More');

const go = () => {
    sheetOpen.value = false;
    emit('navigate');
};

const search = () => {
    sheetOpen.value = false;
    emit('search');
};
</script>

<template>
    <nav
        class="safe-bottom fixed inset-x-0 bottom-0 z-40 flex border-t border-t-rule bg-paper md:hidden"
        aria-label="Main"
    >
        <Link
            v-for="link in tabs"
            :key="link.href"
            :href="link.href"
            class="-mt-px flex h-topbar flex-1 flex-col items-center justify-center gap-1 border-t-2 text-12 transition duration-fast ease-product"
            :class="matches(link.href) ? 'border-t-accent text-ink' : 'border-t-transparent text-ink-2'"
            :aria-current="matches(link.href) ? 'page' : undefined"
            @click="emit('navigate')"
        >
            <component :is="iconFor(link)" v-if="iconFor(link)" :size="20" :stroke-width="1.5" aria-hidden="true" />
            <span class="max-w-full truncate px-1">{{ link.label }}</span>
        </Link>

        <button
            type="button"
            class="-mt-px flex h-topbar flex-1 flex-col items-center justify-center gap-1 border-t-2 text-12 transition duration-fast ease-product"
            :class="moreCurrent ? 'border-t-accent text-ink' : 'border-t-transparent text-ink-2'"
            :aria-expanded="sheetOpen"
            @click="sheetOpen = true"
        >
            <component :is="moreIcon" v-if="moreIcon" :size="20" :stroke-width="1.5" aria-hidden="true" />
            <span class="max-w-full truncate px-1">More</span>
        </button>
    </nav>

    <SlideOver
        :show="sheetOpen"
        position="bottom"
        title="Everything else"
        @close="sheetOpen = false"
    >
        <ul>
            <li v-for="link in overflow" :key="link.href">
                <Link
                    :href="link.href"
                    class="flex min-h-tap items-center justify-between gap-2 rounded px-2 text-15 transition duration-fast ease-product hover:bg-ink-tint"
                    :class="matches(link.href) ? 'font-medium text-ink' : 'text-ink-2'"
                    :aria-current="matches(link.href) ? 'page' : undefined"
                    @click="go"
                >
                    <span class="truncate">{{ link.label }}</span>
                    <span v-if="link.count !== undefined && link.count !== null" class="numeral shrink-0 text-12 text-ink-2">
                        {{ link.count }}
                    </span>
                </Link>
            </li>
        </ul>

        <div class="mt-4 border-t border-t-rule pt-2">
            <button
                type="button"
                class="flex min-h-tap w-full items-center rounded px-2 text-15 text-ink-2 transition duration-fast ease-product hover:bg-ink-tint"
                @click="search"
            >
                Search
            </button>

            <Link
                v-if="profileHref"
                :href="profileHref"
                class="flex min-h-tap items-center rounded px-2 text-15 text-ink-2 transition duration-fast ease-product hover:bg-ink-tint"
                @click="go"
            >
                {{ userName || 'Your account' }}
            </Link>

            <Link
                v-if="billingHref"
                :href="billingHref"
                class="flex min-h-tap items-center rounded px-2 text-15 text-ink-2 transition duration-fast ease-product hover:bg-ink-tint"
                @click="go"
            >
                Billing
            </Link>

            <Link
                v-if="logoutHref"
                :href="logoutHref"
                method="post"
                as="button"
                class="flex min-h-tap w-full items-center rounded px-2 text-left text-15 text-ink-2 transition duration-fast ease-product hover:bg-ink-tint"
                @click="go"
            >
                Sign out
            </Link>
        </div>
    </SlideOver>
</template>
