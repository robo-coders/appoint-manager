<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    messages: Array<{
        id: number;
        tenant_id: number;
        channel: string;
        type: string;
        to: string;
        status: string;
        body: string;
        created_at: string | null;
    }>;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Send log" />
        <h1 class="font-display text-20">SMS and email</h1>
        <ul class="mt-4 space-y-3 text-13">
            <li v-for="message in messages" :key="message.id" class="border-b border-rule pb-2">
                <span class="text-ink-2">{{ message.created_at }} · tenant {{ message.tenant_id }} · {{ message.channel }} · {{ message.status }}</span>
                <p>{{ message.to }} — {{ message.body }}</p>
            </li>
        </ul>
    </AppLayout>
</template>
