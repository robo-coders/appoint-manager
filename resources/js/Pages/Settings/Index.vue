<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, Link, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    business: {
        name: string;
        timezone: string;
        email: string | null;
        phone: string | null;
        address_line_1: string | null;
        address_line_2: string | null;
        city: string | null;
        postcode: string | null;
    };
    timezones: string[];
}>();

const form = useForm({
    name: props.business.name,
    timezone: props.business.timezone,
    email: props.business.email ?? '',
    phone: props.business.phone ?? '',
    address_line_1: props.business.address_line_1 ?? '',
    address_line_2: props.business.address_line_2 ?? '',
    city: props.business.city ?? '',
    postcode: props.business.postcode ?? '',
});

const submit = () => form.patch(route('settings.update'));
</script>

<template>
    <AppLayout>
        <Head title="Settings" />
        <PageHeader title="Settings" description="Business details and timezone." />
        <p class="mb-4 text-14"><Link :href="route('settings.payments.show')" class="underline">Payments</Link></p>

        <form class="max-w-xl space-y-4 rounded border border-rule bg-white p-6" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-14">Business name</label>
                <input v-model="form.name" class="w-full rounded border border-rule px-3 py-2 text-14" />
            </div>
            <div>
                <label class="mb-1 block text-14">Timezone</label>
                <select v-model="form.timezone" class="w-full rounded border border-rule px-3 py-2 text-14">
                    <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-14">Email</label>
                <input v-model="form.email" type="email" class="w-full rounded border border-rule px-3 py-2 text-14" />
            </div>
            <div>
                <label class="mb-1 block text-14">Phone</label>
                <input v-model="form.phone" class="w-full rounded border border-rule px-3 py-2 text-14" />
            </div>
            <div>
                <label class="mb-1 block text-14">Address line 1</label>
                <input v-model="form.address_line_1" class="w-full rounded border border-rule px-3 py-2 text-14" />
            </div>
            <div>
                <label class="mb-1 block text-14">Address line 2</label>
                <input v-model="form.address_line_2" class="w-full rounded border border-rule px-3 py-2 text-14" />
            </div>
            <div class="grid gap-4 md:grid-cols-2">
                <div>
                    <label class="mb-1 block text-14">City</label>
                    <input v-model="form.city" class="w-full rounded border border-rule px-3 py-2 text-14" />
                </div>
                <div>
                    <label class="mb-1 block text-14">Postcode</label>
                    <input v-model="form.postcode" class="w-full rounded border border-rule px-3 py-2 text-14" />
                </div>
            </div>
            <Button type="submit" :disabled="form.processing">Save</Button>
        </form>
    </AppLayout>
</template>
