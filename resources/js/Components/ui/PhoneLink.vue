<script setup lang="ts">
withDefaults(
    defineProps<{
        phone?: string | null;
        tap?: boolean;
    }>(),
    { phone: null, tap: true },
);
</script>

<template>
    <a
        v-if="phone"
        :href="`tel:${phone}`"
        class="numeral inline-flex items-center underline decoration-rule-strong underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
        :class="tap ? 'min-h-tap md:min-h-0' : ''"
        >{{ phone }}</a
    >
    <span v-else class="numeral text-ink-2">—</span>
</template>
