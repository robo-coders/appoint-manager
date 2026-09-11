<script setup lang="ts">
import iconReversedUrl from '@/assets/icon-reversed.svg';
import iconUrl from '@/assets/icon.svg';
import logoReversedUrl from '@/assets/logo-reversed.svg';
import logoUrl from '@/assets/logo.svg';
import { useTheme } from '@/composables/useTheme';
import { usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = withDefaults(
    defineProps<{
        size?: number;
        variant?: 'mark' | 'lockup';
        reversed?: boolean;
        label?: string;
    }>(),
    { size: 40, variant: 'lockup', reversed: false },
);

const name = computed(() => (usePage().props.appName as string) ?? '');

const altText = computed(() => (props.label === undefined ? name.value : props.label));

const { resolved } = useTheme();

const dark = computed(() => props.reversed || resolved.value === 'dark');

const src = computed(() => {
    if (props.variant === 'mark') return dark.value ? iconReversedUrl : iconUrl;

    return dark.value ? logoReversedUrl : logoUrl;
});

const width = computed(() => Math.round(props.size * (props.variant === 'mark' ? 1 : 260 / 64)));
</script>

<template>
    <img
        :src="src"
        :alt="altText"
        :width="width"
        :height="size"
        :style="{ width: `${width}px`, height: `${size}px` }"
        class="block shrink-0"
        decoding="async"
    />
</template>
