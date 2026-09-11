<script setup lang="ts">
withDefaults(
    defineProps<{
        href?: string;
        tone?: 'muted' | 'ink';
        type?: 'button' | 'submit';
    }>(),
    { tone: 'muted', type: 'button' },
);

const emit = defineEmits<{ click: [] }>();
</script>

<template>
    <component
        :is="href ? 'a' : 'button'"
        :href="href"
        :type="href ? undefined : type"
        class="inline-flex min-h-tap items-center px-3 text-13 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
        :class="tone === 'ink' ? 'text-ink' : 'text-ink-2 hover:text-ink'"
        @click="emit('click')"
    >
        <slot />
    </component>
</template>
