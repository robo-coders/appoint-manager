<script setup lang="ts">
import { usePage } from '@inertiajs/vue3';

const page = usePage();
</script>

<template>
    <div class="flex min-h-screen flex-col items-center bg-paper px-4 py-12">
        <!-- Marketing is a different hostname, so this is a real navigation
             rather than an Inertia visit. -->
        <a :href="page.props.urls.marketing" class="text-17 tracking-20 text-ink">
            {{ page.props.appName }}
        </a>
        <div class="mt-8 w-full max-w-sm rounded border border-rule bg-white p-6">
            <slot />
        </div>
    </div>
</template>
