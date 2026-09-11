<script setup lang="ts">
const props = withDefaults(
    defineProps<{
        time: string;
        selected?: boolean;
        available?: boolean;
        unavailableReason?: string;
    }>(),
    { selected: false, available: true, unavailableReason: 'taken' },
);

const emit = defineEmits<{ pick: [] }>();

const label = () => (props.available ? props.time : `${props.time}, ${props.unavailableReason}`);
</script>

<template>
    <button
        type="button"
        class="min-h-control w-full rounded font-mono text-field transition duration-fast ease-product"
        :class="
            !available
                ? 'border border-rule text-ink-4 line-through'
                : selected
                  ? 'bg-ink text-white'
                  : 'border border-rule bg-white text-ink hover:border-rule-strong'
        "
        :aria-disabled="!available ? 'true' : undefined"
        :aria-pressed="available ? selected : undefined"
        :aria-label="label()"
        @click="available && emit('pick')"
    >
        {{ time }}
    </button>
</template>
