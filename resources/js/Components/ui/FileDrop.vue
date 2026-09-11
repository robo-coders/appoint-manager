<script setup lang="ts">
import Label from '@/Components/ui/Label.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import { ref, useId } from 'vue';

const props = withDefaults(
    defineProps<{
        label: string;
        accept?: string;
        hint?: string;
        error?: string;
        fileName?: string | null;
    }>(),
    { fileName: null },
);

const emit = defineEmits<{ file: [File] }>();

const uid = useId();
const over = ref(false);

const take = (list: FileList | null) => {
    const file = list?.[0];

    if (file) emit('file', file);
};

const onDrop = (event: DragEvent) => {
    over.value = false;
    take(event.dataTransfer?.files ?? null);
};
</script>

<template>
    <div class="space-y-1">
        <Label :for="uid">{{ label }}</Label>

        <label
            :for="uid"
            class="flex min-h-tap cursor-pointer flex-col items-center justify-center rounded border border-dashed px-4 py-6 text-center transition duration-fast ease-product"
            :class="over ? 'border-ink bg-ink-tint' : 'border-rule-strong bg-white hover:border-rule-hover'"
            @dragover.prevent="over = true"
            @dragleave="over = false"
            @drop.prevent="onDrop"
        >
            <span v-if="props.fileName" class="text-14 text-ink">{{ props.fileName }}</span>
            <span v-else class="text-14 text-ink">Drop a CSV here, or choose a file</span>
            <span class="caption mt-1">{{ props.fileName ? 'Drop another to replace it' : 'CSV, first row a header' }}</span>

            <input
                :id="uid"
                type="file"
                :accept="accept"
                class="sr-only"
                :aria-describedby="error ? `${uid}-error` : undefined"
                @change="take(($event.target as HTMLInputElement).files)"
            />
        </label>

        <p v-if="hint && !error" class="text-12 text-ink-2">{{ hint }}</p>
        <FieldError :id="`${uid}-error`" :message="error" />
    </div>
</template>
