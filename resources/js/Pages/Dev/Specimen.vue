<script setup lang="ts">
import Label from '@/Components/ui/Label.vue';

/** One entry in the gallery: a title, a note on when to use it, and the states. */
defineProps<{ name: string; note?: string }>();
</script>

<template>
    <section :id="name.toLowerCase().replace(/[^a-z0-9]+/g, '-')" class="scroll-mt-16 border-t border-rule pt-6">
        <div class="mb-4">
            <h2 class="text-17">{{ name }}</h2>
            <p v-if="note" class="mt-1 max-w-measure text-13 text-ink-2">{{ note }}</p>
        </div>
        <div class="space-y-6"><slot /></div>
    </section>
</template>
