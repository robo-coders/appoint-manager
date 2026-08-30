<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    customer: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        notes: string | null;
        subjects: Array<{ id: number; name: string; attributes: Record<string, string> }>;
        bookings: Array<{
            id: number;
            service_name: string;
            starts_at_local: string;
            status: string;
        }>;
    };
}>();
</script>

<template>
    <AppLayout>
        <Head :title="customer.name" />
        <PageHeader :title="customer.name" :description="customer.email ?? undefined" />
        <div class="grid gap-6 md:grid-cols-2">
            <section class="rounded border border-rule bg-white p-6">
                <h2 class="text-14 font-medium">Details</h2>
                <p class="mt-2 text-14">{{ customer.email || 'No email' }}</p>
                <p class="mt-2 text-14">{{ customer.phone || 'No phone' }}</p>
                <ul class="mt-4 space-y-2 text-14">
                    <li v-for="subject in customer.subjects" :key="subject.id">{{ subject.name }}</li>
                    <li v-if="customer.subjects.length === 0" class="text-ink-2">None yet.</li>
                </ul>
            </section>
            <section class="rounded border border-rule bg-white p-6">
                <h2 class="text-14 font-medium">History</h2>
                <ul class="mt-4 space-y-2 text-14">
                    <li v-for="booking in customer.bookings" :key="booking.id">
                        <Link :href="route('bookings.show', booking.id)" class="underline">
                            {{ booking.starts_at_local }} · {{ booking.service_name }}
                        </Link>
                        <span class="text-ink-2"> {{ booking.status }}</span>
                    </li>
                    <li v-if="customer.bookings.length === 0" class="text-ink-2">No bookings yet.</li>
                </ul>
                <div class="mt-6 flex gap-4 text-13">
                    <a :href="route('customers.export', customer.id)" class="underline">Export data</a>
                    <Link
                        :href="route('customers.destroy', customer.id)"
                        method="delete"
                        as="button"
                        class="text-ink-2 underline"
                    >
                        Delete record
                    </Link>
                </div>
            </section>
        </div>
    </AppLayout>
</template>
