<script setup lang="ts">
withDefaults(defineProps<{ danger?: boolean; disabled?: boolean }>(), { danger: false, disabled: false });
</script>

<template>
    <button
        type="button"
        role="menuitem"
        :disabled="disabled"
        class="block w-full px-3 py-1.5 text-left text-13 transition duration-fast ease-product disabled:cursor-not-allowed disabled:text-ink-3"
        :class="danger ? 'text-danger hover:bg-paper-sunk' : 'text-ink-2 hover:bg-paper-sunk hover:text-ink'"
    >
        <slot />
    </button>
</template>
