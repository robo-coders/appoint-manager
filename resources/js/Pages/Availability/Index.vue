<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SaveState from '@/Components/ui/SaveState.vue';
import WeeklyHoursGrid from '@/Components/WeeklyHoursGrid.vue';
import type { AvailabilityRange } from '@/types/models';
import { Head, router, useForm } from '@inertiajs/vue3';
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

/*
 * Whether there is anything to save. Compared as a stable string rather than by
 * identity, because `WeeklyHoursGrid` replaces the array on every keystroke and
 * an identity check would always say "dirty".
 */
const shape = (ranges: AvailabilityRange[]) =>
    JSON.stringify(ranges.map((range) => [range.weekday, range.start_time, range.end_time]).sort());

const dirty = computed(() => shape(localRanges.value) !== shape(rangesForSelected.value));
const savedAt = ref<number | null>(null);

const save = () => {
    form.ranges = localRanges.value.map((range) => ({
        weekday: range.weekday,
        start_time: range.start_time,
        end_time: range.end_time,
    }));
    form.put(route('availability.sync', selectedId.value), {
        preserveScroll: true,
        onSuccess: () => (savedAt.value = Date.now()),
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Availability" />
        <PageHeader title="Availability" description="Weekly hours for each person." />

        <!-- One person at a time. Seven days times four groomers is
             twenty-eight cards, which is a wall rather than a form. -->
        <div class="mb-6 flex flex-wrap gap-2" role="group" aria-label="Whose hours">
            <Button
                v-for="person in staff"
                :key="person.id"
                :variant="selectedId === person.id ? 'primary' : 'secondary'"
                @click="selectedId = person.id"
            >
                {{ person.name }}
            </Button>
        </div>

        <EmptyState
            v-if="staff.length === 0"
            title="Nobody to set hours for"
            description="Add someone who takes appointments first — hours belong to a person, not to the salon."
            action-label="Add staff"
            @action="router.visit(route('staff.index'))"
        />

        <div v-else-if="selectedStaff.length" class="rounded border border-rule bg-white p-6">
            <WeeklyHoursGrid v-model="localRanges" :staff="selectedStaff" />

            <div class="mt-6 flex items-center gap-4">
                <Button :loading="form.processing" @click="save">Save hours</Button>
                <!-- The same save-state indicator as Settings: a form that
                     silently succeeds is a form you save twice. -->
                <SaveState :dirty="dirty" :processing="form.processing" :saved-at="savedAt" />
            </div>

            <FieldError :message="form.errors.ranges" class="mt-2" />
        </div>
    </AppLayout>
</template>
