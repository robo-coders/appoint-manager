<script setup lang="ts">
/** Inline, below the control, linked by aria-describedby. Never a toast alone. */
defineProps<{ id?: string; message?: string }>();
</script>

<template>
    <p v-if="message" :id="id" class="text-12 text-danger" role="alert">{{ message }}</p>
</template>
