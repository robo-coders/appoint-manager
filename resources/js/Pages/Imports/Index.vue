<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';

const page = usePage();
const customers = useForm({ csv: '', commit: false });
const bookings = useForm({ csv: '', commit: false });
</script>

<template>
    <AppLayout>
        <Head title="Import" />
        <PageHeader title="Import" description="Preview first. Then commit." />
        <div class="grid gap-8 md:grid-cols-2">
            <form class="space-y-2" @submit.prevent="customers.post(route('imports.customers'))">
                <h2 class="font-display text-17">Customers</h2>
                <p class="text-13 text-ink-2">name, email, phone, subjects (semicolon-separated)</p>
                <textarea v-model="customers.csv" rows="8" class="w-full" />
                <label class="flex items-center gap-2 text-13">
                    <input v-model="customers.commit" type="checkbox" />
                    Commit (otherwise dry-run)
                </label>
                <button type="submit" class="min-h-tap underline">Run customer import</button>
            </form>
            <form class="space-y-2" @submit.prevent="bookings.post(route('imports.bookings'))">
                <h2 class="font-display text-17">Bookings</h2>
                <p class="text-13 text-ink-2">customer_email, service_name, staff_email, starts_at, subject_name</p>
                <textarea v-model="bookings.csv" rows="8" class="w-full" />
                <label class="flex items-center gap-2 text-13">
                    <input v-model="bookings.commit" type="checkbox" />
                    Commit (otherwise dry-run)
                </label>
                <button type="submit" class="min-h-tap underline">Run booking import</button>
            </form>
        </div>
        <ul v-if="page.props.preview" class="mt-8 text-13">
            <li v-for="(row, index) in page.props.preview as Array<{ ok: boolean; message: string; row: number }>" :key="index">
                Row {{ row.row }}: {{ row.ok ? 'ok' : 'skip' }} — {{ row.message }}
            </li>
        </ul>
    </AppLayout>
</template>
