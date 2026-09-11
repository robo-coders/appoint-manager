<script setup lang="ts">
import Banner from '@/Components/ui/Banner.vue';
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const page = usePage();

const beta = computed(() => page.props.tenant?.is_beta === true);
</script>

<template>
    <Banner v-if="beta" row>
        <span class="eyebrow inline-flex h-badge items-center rounded border border-accent-rule px-2 text-accent">
            Sandbox
        </span>
        <span class="text-ink-2">
            You are using a beta preview of {{ page.props.appName }}. Payments are test-only — no card is ever charged.
        </span>
        <span aria-hidden="true" class="hidden h-3 w-px bg-rule-strong sm:block"></span>
        <Link :href="route('beta-sandbox.show')" class="underline decoration-rule underline-offset-4">
            Sandbox tools
        </Link>
    </Banner>
</template>
