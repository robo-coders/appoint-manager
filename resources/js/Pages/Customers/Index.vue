<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    customers: Array<{
        id: number;
        name: string;
        email: string;
        phone: string | null;
        subjects_count: number;
        bookings_count: number;
    }>;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Customers" />
        <PageHeader title="Customers" :description="`People who have booked with you.`" />
        <div class="overflow-x-auto rounded border border-rule bg-white">
            <table class="w-full min-w-[380px] text-left text-14">
                <thead class="border-b border-rule text-ink-2">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Bookings</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="customer in customers" :key="customer.id" class="border-b border-rule">
                        <td class="px-4 py-3">
                            <Link :href="route('customers.show', customer.id)" class="underline">{{ customer.name }}</Link>
                        </td>
                        <td class="px-4 py-3">{{ customer.email }}</td>
                        <td class="px-4 py-3">{{ customer.bookings_count }}</td>
                    </tr>
                    <tr v-if="customers.length === 0">
                        <td colspan="3" class="px-4 py-6 text-ink-2">No customers yet. They appear here after a booking.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
