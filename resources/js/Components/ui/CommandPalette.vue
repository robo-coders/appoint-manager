<script setup lang="ts">
import { router, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, nextTick, ref, watch } from 'vue';

const props = defineProps<{
    show: boolean;
}>();

const emit = defineEmits<{
    close: [];
    create: [];
}>();

interface CustomerHit {
    id: number;
    name: string;
    email: string;
}

const page = usePage();
const query = ref('');
const customers = ref<CustomerHit[]>([]);
const active = ref(0);
const input = ref<HTMLInputElement | null>(null);

const nav = computed(() => [
    { id: 'diary', label: 'Go to diary', href: route('diary.index') },
    { id: 'bookings', label: 'Go to bookings', href: route('bookings.index') },
    { id: 'customers', label: 'Go to customers', href: route('customers.index') },
    { id: 'waitlist', label: 'Go to waitlist', href: route('waitlist.index') },
    { id: 'services', label: 'Go to services', href: route('services.index') },
    { id: 'staff', label: 'Go to staff', href: route('staff.index') },
    { id: 'overview', label: 'Go to overview', href: route('dashboard') },
    { id: 'new', label: 'Create a booking', href: null },
    { id: 'today', label: 'Jump to today', href: route('diary.index', { date: page.props.today }) },
]);

const filteredNav = computed(() => {
    const term = query.value.trim().toLowerCase();

    if (!term) {
        return nav.value;
    }

    return nav.value.filter((item) => item.label.toLowerCase().includes(term));
});

const items = computed(() => [
    ...filteredNav.value.map((item) => ({ kind: 'nav' as const, ...item })),
    ...customers.value.map((customer) => ({
        kind: 'customer' as const,
        id: `c-${customer.id}`,
        label: customer.name,
        href: route('customers.show', customer.id),
        detail: customer.email,
    })),
]);

watch(
    () => props.show,
    async (open) => {
        if (!open) {
            return;
        }

        query.value = '';
        customers.value = [];
        active.value = 0;
        await nextTick();
        input.value?.focus();
    },
);

watch(query, async (value) => {
    active.value = 0;
    const term = value.trim();

    if (term.length < 2) {
        customers.value = [];
        return;
    }

    const { data } = await axios.get(route('search'), { params: { q: term } });
    customers.value = data.customers ?? [];
});

const run = (index: number) => {
    const item = items.value[index];

    if (!item) {
        return;
    }

    if (item.kind === 'nav' && item.id === 'new') {
        emit('create');
        emit('close');
        return;
    }

    if (item.href) {
        router.visit(item.href);
    }

    emit('close');
};

const onKey = (event: KeyboardEvent) => {
    if (event.key === 'ArrowDown') {
        event.preventDefault();
        active.value = Math.min(active.value + 1, items.value.length - 1);
    }

    if (event.key === 'ArrowUp') {
        event.preventDefault();
        active.value = Math.max(active.value - 1, 0);
    }

    if (event.key === 'Enter') {
        event.preventDefault();
        run(active.value);
    }

    if (event.key === 'Escape') {
        emit('close');
    }
};
</script>

<template>
    <Teleport to="body">
        <div v-if="show" class="fixed inset-0 z-50 flex items-start justify-center px-4 pt-[15vh]">
            <div class="absolute inset-0 bg-overlay" @click="emit('close')" />
            <div
                class="appear relative w-full max-w-lg overflow-hidden rounded border border-rule bg-white"
                role="dialog"
                aria-modal="true"
                aria-label="Command palette"
            >
                <label class="sr-only" for="command-input">Search</label>
                <input
                    id="command-input"
                    ref="input"
                    v-model="query"
                    class="h-12 w-full border-0 border-b border-b-rule bg-transparent px-4 text-14 text-ink"
                    placeholder="Jump to a page, customer, or date"
                    @keydown="onKey"
                />
                <ul class="max-h-80 overflow-y-auto py-2">
                    <li v-for="(item, index) in items" :key="item.id">
                        <button
                            type="button"
                            class="flex min-h-tap w-full items-center justify-between px-4 text-left text-14"
                            :class="index === active ? 'bg-paper-sunk text-ink' : 'text-ink-2 hover:bg-paper-sunk hover:text-ink'"
                            @mouseenter="active = index"
                            @click="run(index)"
                        >
                            <span>{{ item.label }}</span>
                            <span v-if="'detail' in item && item.detail" class="text-12 text-ink-2">{{ item.detail }}</span>
                        </button>
                    </li>
                    <li v-if="items.length === 0" class="px-4 py-6 text-14 text-ink-2">Nothing matches that.</li>
                </ul>
            </div>
        </div>
    </Teleport>
</template>
