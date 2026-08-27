<script setup lang="ts">
import { inject } from 'vue';
import { MENU_CLOSE } from './menuClose';

withDefaults(defineProps<{ danger?: boolean; disabled?: boolean }>(), { danger: false, disabled: false });

const emit = defineEmits<{ click: [] }>();

/*
 * Close first, then act. An item that opens a modal used to leave the menu on
 * screen behind the overlay; closing here rather than relying on the click
 * bubbling to the panel means the outcome does not depend on what the handler
 * does next. Injected, so an item used outside a `Menu` still works.
 */
const close = inject(MENU_CLOSE, null);

const onClick = () => {
    close?.();
    emit('click');
};
</script>

<template>
    <button
        type="button"
        role="menuitem"
        :disabled="disabled"
        class="block w-full px-3 py-1.5 text-left text-13 transition duration-fast ease-product disabled:cursor-not-allowed disabled:text-ink-3"
        :class="danger ? 'text-danger hover:bg-paper-sunk' : 'text-ink-2 hover:bg-paper-sunk hover:text-ink'"
        @click="onClick"
    >
        <slot />
    </button>
</template>
