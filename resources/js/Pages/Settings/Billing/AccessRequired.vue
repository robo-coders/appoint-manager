<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineProps<{
    gate: {
        variant: 'trial_ended' | 'unpaid' | 'cancelled_ended';
        title: string;
        body: string;
        action: string;
    };
}>();
</script>

<template>
    <div class="flex min-h-screen flex-col items-start justify-center bg-paper px-6">
        <Head :title="gate.title" />
        <p class="eyebrow">Billing</p>
        <h1 class="display-light mt-3 max-w-measure text-34">{{ gate.title }}</h1>
        <p class="mt-3 max-w-measure text-14 text-ink-2">{{ gate.body }}</p>
        <Link
            :href="route('settings.billing')"
            class="mt-6 inline-flex min-h-tap items-center rounded bg-accent px-4 text-14 font-medium text-paper hover:bg-accent-strong"
        >
            {{ gate.action }}
        </Link>
    </div>
</template>
