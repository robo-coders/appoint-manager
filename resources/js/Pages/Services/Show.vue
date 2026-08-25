<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Money from '@/Components/ui/Money.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import type { ServiceRecord } from '@/types/models';
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    service: ServiceRecord;
}>();
</script>

<template>
    <AppLayout>
        <Head :title="service.name" />
        <PageHeader :title="service.name">
            <Link :href="route('services.index')" class="text-14 underline">Back to services</Link>
        </PageHeader>
        <div class="rounded border border-rule bg-white p-6 text-14">
            <p>{{ service.duration_minutes }} minutes</p>
            <p class="mt-2"><Money :value="service.price" /></p>
        </div>
    </AppLayout>
</template>
