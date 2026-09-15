<script setup lang="ts">
import AppLogo from '@/Components/AppLogo.vue';
import { computed } from 'vue';

export type SetupStep = { key: string; label: string };

const props = defineProps<{
    steps: SetupStep[];
    current: string;
}>();

const index = computed(() => Math.max(0, props.steps.findIndex((step) => step.key === props.current)));
const label = computed(() => props.steps.find((step) => step.key === props.current)?.label ?? '');
const stepCounter = computed(() => `STEP ${index.value + 1} OF ${props.steps.length}`);
</script>

<template>
    <div class="flex min-h-screen flex-col bg-paper">
        <div
            class="flex gap-1"
            role="progressbar"
            aria-valuemin="1"
            :aria-valuenow="index + 1"
            :aria-valuemax="steps.length"
            :aria-valuetext="`Step ${index + 1} of ${steps.length}, ${label}`"
        >
            <div
                v-for="(setupStep, at) in steps"
                :key="setupStep.key"
                class="h-1 flex-1 transition duration-fast ease-product"
                :class="at <= index ? 'bg-accent' : 'bg-rule'"
            />
        </div>

        <div class="flex items-center justify-between gap-6 border-b border-rule px-6 py-4 md:px-12">
            <div class="flex items-center gap-3">
                <AppLogo :size="26" variant="mark" />
                <p class="eyebrow">DiaryDesk setup · {{ label }}</p>
            </div>
            <span class="numeral text-12 text-ink-3">{{ stepCounter }}</span>
        </div>

        <div class="mx-auto w-full max-w-3xl flex-1 px-6 pb-16 pt-12 md:px-12">
            <slot />
        </div>

        <div
            class="sticky bottom-0 flex items-center justify-between gap-6 border-t border-rule bg-paper px-6 py-4 md:px-12"
        >
            <slot name="footer-start"><span /></slot>

            <div class="flex items-center gap-6">
                <span class="numeral text-12 text-ink-2">{{ stepCounter }}</span>
                <slot name="footer-end" />
            </div>
        </div>
    </div>
</template>
