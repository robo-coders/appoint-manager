<script setup lang="ts">
/** One sentence and one action. Never a shrug. */
defineProps<{ title: string; description?: string; actionLabel?: string }>();

const emit = defineEmits<{ action: [] }>();
</script>

<template>
    <div class="rounded border border-rule bg-white px-6 py-12 text-center">
        <p class="text-14 text-ink">{{ title }}</p>
        <p v-if="description" class="mx-auto mt-1 max-w-measure text-13 text-ink-2">{{ description }}</p>
        <div v-if="actionLabel || $slots.default" class="mt-4 flex justify-center">
            <slot>
                <button
                    type="button"
                    class="min-h-tap text-13 text-ink underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                    @click="emit('action')"
                >
                    {{ actionLabel }}
                </button>
            </slot>
        </div>
    </div>
</template>
