<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import type { Money } from '@/types/models';
import { Head, Link, router } from '@inertiajs/vue3';
import { reactive } from 'vue';

interface BookingRow {
    id: number;
    customer_name: string;
    service_name: string;
    staff_name: string;
    starts_at_local: string;
    status: string;
    source: string;
    price_at_booking: Money;
}

const props = defineProps<{
    filters: { status: string; from: string; to: string };
    bookings: BookingRow[];
}>();

const filters = reactive({ ...props.filters });

const apply = () => {
    router.get(route('bookings.index'), { ...filters }, { preserveState: true, replace: true });
};
</script>

<template>
    <AppLayout>
        <Head title="Bookings" />
        <PageHeader title="Bookings" description="Filter by status and date." />

        <form class="mb-6 grid gap-3 md:grid-cols-4" @submit.prevent="apply">
            <label class="text-13">
                Status
                <select v-model="filters.status" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3 text-14">
                    <option value="">All statuses</option>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="cancelled">Cancelled</option>
                    <option value="completed">Completed</option>
                    <option value="no_show">No show</option>
                </select>
            </label>
            <label class="text-13">
                From
                <input v-model="filters.from" type="date" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3 text-14" />
            </label>
            <label class="text-13">
                To
                <input v-model="filters.to" type="date" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3 text-14" />
            </label>
            <button type="submit" class="min-h-tap self-end rounded border border-rule bg-white px-3 text-14">Apply filters</button>
        </form>

        <div class="overflow-x-auto rounded border border-rule bg-white">
            <table class="w-full min-w-[380px] text-left text-14">
                <thead class="border-b border-rule text-ink-2">
                    <tr>
                        <th class="px-4 py-3 font-medium">When</th>
                        <th class="px-4 py-3 font-medium">Client</th>
                        <th class="px-4 py-3 font-medium">Service</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="booking in bookings" :key="booking.id" class="border-b border-rule">
                        <td class="px-4 py-3">
                            <Link :href="route('bookings.show', booking.id)" class="underline">
                                {{ booking.starts_at_local }}
                            </Link>
                        </td>
                        <td class="px-4 py-3">{{ booking.customer_name }}</td>
                        <td class="px-4 py-3">{{ booking.service_name }}</td>
                        <td class="px-4 py-3">{{ booking.status }}</td>
                    </tr>
                    <tr v-if="bookings.length === 0">
                        <td colspan="4" class="px-4 py-6 text-ink-2">No bookings in this range. Open the diary to add one.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
