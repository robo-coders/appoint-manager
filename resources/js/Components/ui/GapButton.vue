<script setup lang="ts">
withDefaults(
    defineProps<{
        minutes: number;
        ariaLabel: string;
        labelFrom?: number;
    }>(),
    { labelFrom: 30 },
);

const emit = defineEmits<{ book: [] }>();
</script>

<template>
    <button
        type="button"
        class="group h-full w-full rounded text-left transition duration-fast ease-product hover:bg-ink-tint"
        :aria-label="ariaLabel"
        @click="emit('book')"
    >
        <span
            v-if="minutes >= labelFrom"
            class="numeral flex h-full items-center justify-center text-12 text-ink-3 transition duration-fast ease-product group-hover:text-ink-2"
        >
            {{ minutes }} min
        </span>
    </button>
</template>
