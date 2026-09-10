<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import { toast } from '@/lib/toast';
import { useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    customerId: number;
    text: string | null;
    editorName: string | null;
    updatedAt: string | null;
}>();

const FALLBACK_ERROR = 'That note did not save. Check your connection and try again.';

const form = useForm({ notes: props.text ?? '' });

const failure = ref<string | null>(null);
const baseline = ref(props.text ?? '');

watch(
    () => props.text,
    (next) => (baseline.value = next ?? ''),
);

const changed = computed(() => (form.notes ?? '') !== baseline.value);

const error = computed<string | null>(() => form.errors.notes ?? failure.value);

const meta = computed(() =>
    props.editorName && props.updatedAt
        ? `Last edited by ${props.editorName} · ${props.updatedAt}`
        : 'Not edited yet',
);

const save = () => {
    failure.value = null;

    form.patch(route('customers.notes.update', props.customerId), {
        preserveScroll: true,
        onSuccess: () => {
            baseline.value = form.notes ?? '';
        },
        onError: (errors: Record<string, string>) => {
            failure.value = errors.notes ?? FALLBACK_ERROR;
            toast.error(failure.value, { actionLabel: 'Retry', onAction: save });
        },
    });
};
</script>

<template>
    <section class="rounded border border-rule bg-white p-4">
        <div class="mb-3 flex flex-wrap items-baseline justify-between gap-3">
            <h2 class="text-14 font-medium">Notes</h2>
            <p class="text-12 text-ink-2">Only you and your staff see this</p>
        </div>

        <Textarea
            v-model="form.notes"
            label="Notes"
            label-hidden
            :rows="4"
            placeholder="Anything worth knowing before the next visit."
            :error="error ?? undefined"
        />

        <div class="mt-3 flex flex-wrap items-center justify-between gap-3">
            <p class="text-12 text-ink-2">{{ meta }}</p>
            <div class="flex items-center gap-3">
                <Button
                    variant="secondary"
                    :disabled="!changed || form.processing"
                    :loading="form.processing"
                    data-testid="customer-notes-save"
                    @click="save"
                >
                    Save note
                </Button>
            </div>
        </div>
    </section>
</template>
