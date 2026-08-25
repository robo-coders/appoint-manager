<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, usePage } from '@inertiajs/vue3';

defineProps<{
    stats: Array<{ key: string; label: string; value: string; hint?: string; highlight?: boolean }>;
    today: Array<{ id: number; time: string; customer: string; service: string; staff: string }>;
}>();

const page = usePage();
</script>

<template>
    <AppLayout>
        <Head title="Overview" />
        <PageHeader title="Overview" :description="`Hello ${page.props.auth.user?.name}.`" />

        <div class="grid gap-4 md:grid-cols-2">
            <div
                v-for="stat in stats"
                :key="stat.key"
                class="rounded border bg-white p-6"
                :class="stat.highlight ? 'border-ink' : 'border-rule'"
            >
                <p class="text-14 text-ink-2">{{ stat.label }}</p>
                <p class="mt-2 text-24 font-medium">{{ stat.value }}</p>
                <p v-if="stat.hint" class="mt-1 text-14 text-ink-2">{{ stat.hint }}</p>
            </div>
        </div>

        <div class="mt-6 rounded border border-rule bg-white p-6">
            <h2 class="text-14 font-medium">Today</h2>
            <ul v-if="today.length" class="mt-3 space-y-2 text-14">
                <li v-for="row in today" :key="row.id">{{ row.time }} · {{ row.customer }} · {{ row.service }}</li>
            </ul>
            <p v-else class="mt-3 text-14 text-ink-2">Nothing on the diary today. Open the diary to add a booking.</p>
        </div>
    </AppLayout>
</template>
