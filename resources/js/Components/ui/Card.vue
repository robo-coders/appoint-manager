<script setup lang="ts">
import Label from './Label.vue';

defineProps<{ title?: string; flush?: boolean }>();
</script>

<template>
    <section class="rounded border border-rule bg-white" :class="flush ? '' : 'p-4'">
        <header v-if="title || $slots.actions" class="mb-3 flex items-center justify-between gap-4" :class="flush ? 'p-4 pb-0' : ''">
            <Label v-if="title">{{ title }}</Label>
            <slot name="actions" />
        </header>
        <slot />
    </section>
</template>
