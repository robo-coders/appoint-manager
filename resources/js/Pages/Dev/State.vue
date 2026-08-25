<script setup lang="ts">
import Label from '@/Components/ui/Label.vue';

defineProps<{ name: string }>();
</script>

<template>
    <div>
        <Label>{{ name }}</Label>
        <div class="mt-2"><slot /></div>
    </div>
</template>
