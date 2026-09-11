<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        weekday: string;
        dayOfMonth: string;
        fullLabel: string;
        selected?: boolean;
        available?: boolean;
        unavailableReason?: string;
    }>(),
    { selected: false, available: true, unavailableReason: 'no times' },
);

const emit = defineEmits<{ pick: [] }>();
</script>

<template>
    <button
        type="button"
        class="flex min-h-row w-full flex-col items-center justify-center rounded transition duration-fast ease-product"
        :class="
            !available
                ? 'border border-rule'
                : selected
                  ? 'bg-ink text-white'
                  : 'border border-rule bg-white hover:border-rule-strong'
        "
        :aria-disabled="!available ? 'true' : undefined"
        :aria-pressed="available ? selected : undefined"
        :aria-label="available ? fullLabel : `${fullLabel}, ${unavailableReason}`"
        @click="available && emit('pick')"
    >
        <span class="text-12" :class="!available ? 'text-ink-4' : selected ? '' : 'text-ink-2'" aria-hidden="true">
            {{ weekday }}
        </span>
        <span class="font-mono text-13" :class="!available ? 'text-ink-4 line-through' : ''" aria-hidden="true">
            {{ dayOfMonth }}
        </span>
    </button>
</template>
