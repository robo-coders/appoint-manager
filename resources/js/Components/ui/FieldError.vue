<script setup lang="ts">
defineProps<{ id?: string; message?: string }>();
</script>

<template>
    <p v-if="message" :id="id" class="text-12 text-danger" role="alert"><slot>{{ message }}</slot></p>
</template>
