<script setup lang="ts">
import { useId } from 'vue';

const model = defineModel<boolean>({ default: false });

defineProps<{ label: string }>();

const uid = useId();
</script>

<template>
    <label class="ed-check" :for="uid">
        <input :id="uid" v-model="model" type="checkbox" class="ed-check-box" />
        {{ label }}
    </label>
</template>
