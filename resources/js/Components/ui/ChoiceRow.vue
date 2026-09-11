<script setup lang="ts">
withDefaults(
    defineProps<{
        label: string;
        meta?: string;
        note?: string;
        ariaLabel?: string;
    }>(),
    {},
);

const emit = defineEmits<{ pick: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex min-h-row w-full items-baseline justify-between gap-3 border-b border-b-rule py-3 text-left transition duration-fast ease-product hover:border-b-rule-strong"
        :aria-label="ariaLabel"
        @click="emit('pick')"
    >
        <span class="min-w-0">
            <span class="block text-field">{{ label }}</span>
            <span v-if="note" class="caption mt-1 block">{{ note }}</span>
        </span>
        <span v-if="meta" class="whitespace-nowrap font-mono text-field text-ink-2">{{ meta }}</span>
    </button>
</template>
