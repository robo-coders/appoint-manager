<script setup lang="ts">
import Spinner from './Spinner.vue';

withDefaults(
    defineProps<{
        type?: 'button' | 'submit';
        variant?: 'solid' | 'outline';
        disabled?: boolean;
        loading?: boolean;
        block?: boolean;
    }>(),
    { type: 'button', variant: 'solid', disabled: false, loading: false, block: false },
);
</script>

<template>
    <button
        :type="type"
        :disabled="disabled || loading"
        :aria-busy="loading ? 'true' : undefined"
        class="ed-btn"
        :class="[`ed-btn--${variant}`, block ? 'ed-btn--block' : '']"
    >
        <span :class="loading ? 'ed-btn-label--loading' : ''"><slot /></span>
        <span v-if="loading" class="ed-btn-spinner"><Spinner /></span>
    </button>
</template>
