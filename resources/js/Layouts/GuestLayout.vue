<script setup lang="ts">
import AppLogo from '@/Components/AppLogo.vue';
import StepProgress, { type Step } from '@/Components/ui/StepProgress.vue';
import { usePage } from '@inertiajs/vue3';

defineProps<{
    title: string;
    lede?: string;
    quiet?: boolean;
    steps?: Step[];
    currentStep?: string;
    completedSteps?: string[];
    displayTitle?: boolean;
}>();

const page = usePage();
</script>

<template>
    <div class="flex min-h-screen bg-paper">
        <div class="flex w-full flex-col px-6 py-8 lg:basis-auth-col md:px-12 md:py-12 lg:px-16">
            <a
                :href="page.props.urls.marketing"
                class="inline-flex w-fit rounded transition duration-fast ease-product hover:opacity-70"
            >
                <AppLogo :size="40" />
            </a>

            <div class="mt-12 w-full max-w-auth-form md:my-auto md:mt-auto">
                <StepProgress
                    v-if="steps && currentStep"
                    class="mb-8 lg:hidden"
                    variant="compact"
                    :steps="steps"
                    :current="currentStep"
                    :completed="completedSteps ?? []"
                />
                <h1 class="text-24 tracking-24" :class="displayTitle ? 'display-light' : ''">{{ title }}</h1>
                <p v-if="lede" class="mt-2 text-14 text-ink-2">{{ lede }}</p>

                <div class="mt-8">
                    <slot />
                </div>
            </div>

            <div v-if="$slots.foot" class="mt-12 w-full max-w-auth-form pt-6 md:mt-auto">
                <slot name="foot" />
            </div>
        </div>

        <aside
            v-if="!quiet"
            class="hidden border-l border-l-rule bg-paper-sunk px-12 py-12 lg:flex lg:flex-1 lg:flex-col lg:justify-center lg:px-16"
        >
            <template v-if="steps && currentStep">
                <p class="caption mb-4">Setting up</p>
                <StepProgress
                    class="-ml-2 max-w-auth-form"
                    variant="rail"
                    :steps="steps"
                    :current="currentStep"
                    :completed="completedSteps ?? []"
                />
            </template>
            <template v-else>
                <p class="max-w-auth-form text-17 tracking-17 text-ink">
                    {{ page.props.auth_panel.headline }}
                </p>
                <p class="mt-3 max-w-auth-form text-14 leading-body text-ink-2">
                    {{ page.props.auth_panel.body }}
                </p>
                <div v-if="$slots.aside" class="mt-8 w-full max-w-auth-form">
                    <slot name="aside" />
                </div>
            </template>
        </aside>
    </div>
</template>
