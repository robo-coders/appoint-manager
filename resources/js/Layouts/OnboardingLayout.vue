<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    step: string;
    completedSteps: string[];
}>();

const page = usePage();

const steps = computed(() => [
    { key: 'business', label: 'Business' },
    { key: 'services', label: 'Services' },
    { key: 'staff', label: 'Staff' },
    { key: 'hours', label: 'Hours' },
]);
</script>

<template>
    <div class="min-h-screen bg-paper">
        <header class="border-b border-rule bg-white px-6 py-4">
            <p class="text-13 font-medium">{{ page.props.appName }}</p>
            <p class="text-13 text-ink-2">{{ page.props.tenant?.name }}</p>
        </header>
        <div class="mx-auto max-w-4xl px-4 py-8">
            <ol class="mb-8 flex flex-wrap gap-2 text-13">
                <li v-for="item in steps" :key="item.key">
                    <Link
                        :href="route('onboarding.show', { step: item.key })"
                        class="rounded border px-3 py-1"
                        :class="
                            props.step === item.key
                                ? 'border-ink text-ink'
                                : 'border-rule text-ink-2'
                        "
                    >
                        {{ item.label }}
                        <span v-if="completedSteps.includes(item.key)" class="text-ink-2">
                            saved
                        </span>
                    </Link>
                </li>
            </ol>
            <slot />
        </div>
    </div>
</template>
