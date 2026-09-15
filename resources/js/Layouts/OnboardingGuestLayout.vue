<script setup lang="ts">
import SetupChrome, { type SetupStep } from '@/Components/SetupChrome.vue';

defineProps<{
    title: string;
    lede?: string;
    steps: SetupStep[];
    currentStep: string;
}>();
</script>

<template>
    <SetupChrome :steps="steps" :current="currentStep">
        <slot name="notice" />

        <h1 class="display-light text-34 tracking-34">{{ title }}</h1>
        <p v-if="lede" class="mt-2 max-w-auth-col text-14 text-ink-2">{{ lede }}</p>

        <div class="mt-12 max-w-auth-col">
            <slot />
        </div>

        <template #footer-start>
            <slot name="footer-start" />
        </template>

        <template #footer-end>
            <slot name="footer-end" />
        </template>
    </SetupChrome>
</template>
