<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, useForm } from '@inertiajs/vue3';

defineProps<{
    entries: Array<{
        id: number;
        customer_name: string;
        phone: string | null;
        service_name: string;
        preferred_times: string;
        waiting_since: string;
        is_active: boolean;
    }>;
    services: Array<{ id: number; name: string }>;
}>();

const form = useForm({
    name: '',
    email: '',
    phone: '',
    service_id: '',
    preferred_times: 'any',
});

const submit = () => form.post(route('waitlist.store'));
</script>

<template>
    <AppLayout>
        <Head title="Waitlist" />
        <PageHeader title="Waitlist" description="Who is waiting, for what, and for how long." />

        <form class="mb-6 grid gap-3 rounded border border-rule bg-white p-4 md:grid-cols-5" @submit.prevent="submit">
            <label class="text-13">
                Name
                <input v-model="form.name" class="mt-1 min-h-tap w-full rounded border border-rule px-3 text-14" />
            </label>
            <label class="text-13">
                Email
                <input v-model="form.email" type="email" class="mt-1 min-h-tap w-full rounded border border-rule px-3 text-14" />
            </label>
            <label class="text-13">
                Phone
                <input v-model="form.phone" class="mt-1 min-h-tap w-full rounded border border-rule px-3 text-14" />
            </label>
            <label class="text-13">
                Service
                <select v-model="form.service_id" class="mt-1 min-h-tap w-full rounded border border-rule px-3 text-14">
                    <option value="">Choose a service</option>
                    <option v-for="service in services" :key="service.id" :value="service.id">{{ service.name }}</option>
                </select>
            </label>
            <Button type="submit" :disabled="form.processing">Add to waitlist</Button>
        </form>

        <div class="overflow-x-auto rounded border border-rule bg-white">
            <table class="w-full text-left text-14">
                <thead class="border-b border-rule text-ink-2">
                    <tr>
                        <th class="px-4 py-2">Customer</th>
                        <th class="px-4 py-2">Service</th>
                        <th class="px-4 py-2">Preference</th>
                        <th class="px-4 py-2">Waiting since</th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.id" class="border-b border-rule">
                        <td class="px-4 py-2">{{ entry.customer_name }}</td>
                        <td class="px-4 py-2">{{ entry.service_name }}</td>
                        <td class="px-4 py-2">{{ entry.preferred_times }}</td>
                        <td class="px-4 py-2">{{ entry.waiting_since.slice(0, 10) }}</td>
                    </tr>
                    <tr v-if="entries.length === 0">
                        <td colspan="4" class="px-4 py-6 text-ink-2">Nobody is waiting. Add someone above.</td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
