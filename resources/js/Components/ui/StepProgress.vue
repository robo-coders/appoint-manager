<script setup lang="ts">
import { computed } from 'vue';

export type Step = { key: string; label: string };

const props = defineProps<{
    steps: Step[];
    current: string;
    completed: string[];
    variant: 'rail' | 'compact';
    hrefFor?: (key: string) => string;
}>();

const index = computed(() => Math.max(0, props.steps.findIndex((step) => step.key === props.current)));
const currentStep = computed(() => props.steps[index.value]);
const isDone = (key: string) => props.completed.includes(key);

const linkFor = (key: string) =>
    props.hrefFor && (isDone(key) || key === props.current) ? props.hrefFor(key) : undefined;

const stateOf = (key: string, at: number) => {
    if (key === props.current) return 'You are here';

    return isDone(key) ? 'Done' : `Step ${at + 1}, not yet`;
};
</script>

<template>
    <div v-if="variant === 'compact'">
        <p class="flex items-baseline justify-between gap-4 text-13">
            <span class="text-ink">{{ currentStep?.label }}</span>
            <span class="font-mono text-12 tabular-nums text-ink-2">
                {{ index + 1 }} / {{ steps.length }}
            </span>
        </p>
        <div
            class="mt-2 flex gap-1"
            role="progressbar"
            :aria-valuenow="index + 1"
            aria-valuemin="1"
            :aria-valuemax="steps.length"
            :aria-valuetext="`Step ${index + 1} of ${steps.length}, ${currentStep?.label}`"
        >
            <span
                v-for="(step, at) in steps"
                :key="step.key"
                class="flex-1 border-t"
                :class="at <= index ? 'border-t-accent' : 'border-t-rule-strong'"
            />
        </div>
    </div>

    <ol v-else class="space-y-1">
        <li v-for="(step, at) in steps" :key="step.key">
            <component
                :is="linkFor(step.key) ? 'a' : 'span'"
                :href="linkFor(step.key)"
                class="flex min-h-row items-center gap-3 rounded px-2 text-13"
                :class="[
                    step.key === current ? 'bg-accent-tint text-ink' : 'text-ink-2',
                    linkFor(step.key) && step.key !== current
                        ? 'transition duration-fast ease-product hover:text-ink'
                        : '',
                ]"
                :aria-current="step.key === current ? 'step' : undefined"
            >
                <span class="w-4 shrink-0 font-mono text-12 tabular-nums" aria-hidden="true">
                    {{ at + 1 }}
                </span>
                <span :class="step.key === current ? 'font-medium' : ''">{{ step.label }}</span>
                <span class="sr-only">{{ stateOf(step.key, at) }}</span>
            </component>
        </li>
    </ol>
</template>
