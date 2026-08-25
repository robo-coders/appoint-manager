<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import WeeklyHoursGrid from '@/Components/WeeklyHoursGrid.vue';
import type { AvailabilityRange } from '@/types/models';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

const props = defineProps<{
    staff: Array<{ id: number; name: string; colour: string | null }>;
    rules: AvailabilityRange[];
}>();

const selectedId = ref(props.staff[0]?.id ?? 0);

const rangesForSelected = computed(() =>
    props.rules.filter((rule) => rule.user_id === selectedId.value),
);

const localRanges = ref<AvailabilityRange[]>([...rangesForSelected.value]);

watch(selectedId, () => {
    localRanges.value = props.rules.filter((rule) => rule.user_id === selectedId.value);
});

watch(
    () => props.rules,
    () => {
        localRanges.value = props.rules.filter((rule) => rule.user_id === selectedId.value);
    },
);

const form = useForm({
    ranges: [] as Array<{ weekday: number; start_time: string; end_time: string }>,
});

const selectedStaff = computed(() => props.staff.filter((person) => person.id === selectedId.value));

const save = () => {
    form.ranges = localRanges.value.map((range) => ({
        weekday: range.weekday,
        start_time: range.start_time,
        end_time: range.end_time,
    }));
    form.put(route('availability.sync', selectedId.value));
};
</script>

<template>
    <AppLayout>
        <Head title="Availability" />
        <PageHeader title="Availability" description="Weekly hours for each person." />

        <div class="mb-6 flex flex-wrap gap-2">
            <button
                v-for="person in staff"
                :key="person.id"
                type="button"
                class="rounded border px-3 py-1 text-14"
                :class="selectedId === person.id ? 'border-ink' : 'border-rule'"
                @click="selectedId = person.id"
            >
                {{ person.name }}
            </button>
        </div>

        <div v-if="selectedStaff.length" class="rounded border border-rule bg-white p-6">
            <WeeklyHoursGrid v-model="localRanges" :staff="selectedStaff" />
            <div class="mt-6">
                <Button :disabled="form.processing" @click="save">Save hours</Button>
            </div>
            <p v-if="form.errors.ranges" class="mt-2 text-14 text-danger">{{ form.errors.ranges }}</p>
        </div>
    </AppLayout>
</template>
