<script setup lang="ts">
/**
 * The last row of a list, and the one that adds to it.
 *
 * A dashed hairline rather than a solid one, because it is not a record — it is
 * the shape of the record that is not there yet, which is what a dashed edge has
 * always meant. It is full width and sits in the list rather than above it, so
 * "add another" is where the eye already is after reading the last row instead
 * of back at the top of the page.
 *
 * It borrows the accent on hover only. At rest this is the quietest thing on the
 * screen — a salon with eight groomers should not have a ninth row shouting at
 * her — and the colour arrives when she is already pointing at it, which is the
 * one moment it means anything.
 */
defineProps<{ title: string; description?: string }>();

const emit = defineEmits<{ click: [] }>();
</script>

<template>
    <button
        type="button"
        class="group mt-3 flex w-full items-center gap-4 rounded border border-dashed border-rule-strong px-3 py-4 text-left transition duration-fast ease-product hover:border-accent"
        @click="emit('click')"
    >
        <span
            class="flex size-10 shrink-0 items-center justify-center rounded border border-dashed border-rule-strong text-15 text-ink-2 transition duration-fast ease-product group-hover:border-accent group-hover:text-accent"
            aria-hidden="true"
        >
            +
        </span>
        <span class="min-w-0 flex-1">
            <span class="block text-14 font-medium text-ink transition duration-fast ease-product group-hover:text-accent">
                {{ title }}
            </span>
            <span v-if="description" class="mt-0.5 block text-13 text-ink-2">{{ description }}</span>
        </span>
    </button>
</template>
