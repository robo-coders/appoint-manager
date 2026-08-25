<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

defineProps<{
    tenants: Array<{
        id: number;
        name: string;
        slug: string;
        plan: string;
        status: string;
        trial_ends_at: string | null;
        is_comped: boolean;
        booking_page_live: boolean;
        bookings_this_month: number;
        last_activity_at: string | null;
        preview_url: string | null;
        feature_flags: Record<string, boolean>;
    }>;
}>();

const clone = useForm({ from_tenant_id: '', to_tenant_id: '' });
</script>

<template>
    <AppLayout>
        <Head title="Tenants" />
        <PageHeader title="Tenants" description="Platform view. Writes are audited." />
        <table class="w-full text-left text-13">
            <thead>
                <tr class="border-b border-rule text-ink-2">
                    <th class="py-2">Name</th>
                    <th>Plan</th>
                    <th>Trial</th>
                    <th>Bookings</th>
                    <th>Last seen</th>
                    <th />
                </tr>
            </thead>
            <tbody>
                <tr v-for="tenant in tenants" :key="tenant.id" class="border-b border-rule">
                    <td class="py-2">{{ tenant.name }}</td>
                    <td>{{ tenant.plan }} {{ tenant.status }} {{ tenant.is_comped ? 'comped' : '' }}</td>
                    <td>{{ tenant.trial_ends_at }}</td>
                    <td>{{ tenant.bookings_this_month }}</td>
                    <td>{{ tenant.last_activity_at || '—' }}</td>
                    <td class="space-x-2">
                        <button type="button" class="underline" @click="router.post(route('super-admin.impersonate', tenant.id))">Impersonate</button>
                        <button type="button" class="underline" @click="router.post(route('super-admin.extend-trial', tenant.id))">Extend trial</button>
                        <button type="button" class="underline" @click="router.post(route('super-admin.comp', tenant.id))">Comp</button>
                        <button type="button" class="underline" @click="router.post(route('super-admin.go-live', tenant.id))">Go live</button>
                        <button type="button" class="underline" @click="router.post(route('super-admin.preview', tenant.id))">Preview link</button>
                    </td>
                </tr>
            </tbody>
        </table>
        <form class="mt-8 flex gap-3 text-13" @submit.prevent="clone.post(route('super-admin.clone'))">
            <input v-model="clone.from_tenant_id" placeholder="From tenant id" />
            <input v-model="clone.to_tenant_id" placeholder="To tenant id" />
            <button type="submit" class="underline">Copy setup</button>
        </form>
        <p class="mt-4 text-13">
            <a :href="route('super-admin.messages')" class="underline">Send log</a>
            ·
            <a :href="route('super-admin.failures')" class="underline">Failed jobs</a>
        </p>
    </AppLayout>
</template>
