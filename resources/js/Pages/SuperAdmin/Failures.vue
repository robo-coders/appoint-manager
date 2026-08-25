<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import { Head } from '@inertiajs/vue3';

defineProps<{
    failed_jobs: Array<Record<string, unknown>>;
    webhook_failures: Array<{ id: number; source: string; type: string | null; message: string; created_at: string }>;
}>();
</script>

<template>
    <AppLayout>
        <Head title="Failures" />
        <h1 class="font-display text-20">Failed jobs</h1>
        <pre class="mt-2 overflow-auto text-12">{{ JSON.stringify(failed_jobs, null, 2) }}</pre>
        <h2 class="mt-8 font-display text-17">Webhook failures</h2>
        <ul class="mt-2 text-13">
            <li v-for="row in webhook_failures" :key="row.id">{{ row.source }} · {{ row.type }} · {{ row.message }}</li>
        </ul>
    </AppLayout>
</template>
