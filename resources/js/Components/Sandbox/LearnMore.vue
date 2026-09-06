<script setup lang="ts">
withDefaults(defineProps<{ label?: string }>(), { label: 'Learn more' });
</script>

<template>
    <details class="mt-2">
        <summary
            class="caption inline-flex min-h-tap cursor-pointer list-none items-center underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:text-ink hover:decoration-ink [&::-webkit-details-marker]:hidden"
        >
            {{ label }}
        </summary>
        <div class="mt-2 space-y-2 text-13 text-ink-2">
            <slot />
        </div>
    </details>
</template>
