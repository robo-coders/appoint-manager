<script setup lang="ts">
const model = defineModel<string>({ required: true });
defineProps<{ tabs: Array<{ value: string; label: string; count?: number }> }>();
</script>

<template>
    <div role="tablist" class="flex items-center gap-1 border-b border-rule">
        <button
            v-for="tab in tabs"
            :key="tab.value"
            type="button"
            role="tab"
            :aria-selected="model === tab.value"
            class="-mb-px inline-flex h-control items-center gap-2 border-b px-3 text-13 transition duration-fast ease-product"
            :class="
                model === tab.value
                    ? 'border-ink text-ink'
                    : 'border-transparent text-ink-2 hover:text-ink'
            "
            @click="model = tab.value"
        >
            {{ tab.label }}
            <span v-if="tab.count !== undefined" class="numeral text-12 text-ink-2">{{ tab.count }}</span>
        </button>
    </div>
</template>
