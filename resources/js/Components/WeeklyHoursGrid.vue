<script setup lang="ts">
import { weekdays } from '@/lib/weekdays';
import type { AvailabilityRange } from '@/types/models';

const props = defineProps<{
    staff: Array<{ id: number; name: string }>;
    modelValue: AvailabilityRange[];
}>();

const emit = defineEmits<{
    'update:modelValue': [value: AvailabilityRange[]];
}>();

const rangesFor = (userId: number, weekday: number) =>
    props.modelValue.filter((range) => range.user_id === userId && range.weekday === weekday);

const addRange = (userId: number, weekday: number) => {
    emit('update:modelValue', [
        ...props.modelValue,
        { user_id: userId, weekday, start_time: '09:00', end_time: '17:00' },
    ]);
};

const removeRange = (userId: number, weekday: number, index: number) => {
    let seen = 0;
    emit(
        'update:modelValue',
        props.modelValue.filter((range) => {
            if (range.user_id === userId && range.weekday === weekday) {
                if (seen === index) {
                    seen += 1;
                    return false;
                }
                seen += 1;
            }

            return true;
        }),
    );
};

const updateRange = (
    userId: number,
    weekday: number,
    index: number,
    field: 'start_time' | 'end_time',
    value: string,
) => {
    let seen = 0;
    emit(
        'update:modelValue',
        props.modelValue.map((range) => {
            if (range.user_id === userId && range.weekday === weekday) {
                if (seen === index) {
                    seen += 1;
                    return { ...range, [field]: value };
                }
                seen += 1;
            }

            return range;
        }),
    );
};
</script>

<template>
    <div class="space-y-8">
        <section v-for="person in staff" :key="person.id" class="space-y-3">
            <h3 class="text-14 font-medium text-ink">{{ person.name }}</h3>
            <div class="grid gap-3 md:grid-cols-2">
                <div
                    v-for="day in weekdays"
                    :key="`${person.id}-${day.value}`"
                    class="rounded border border-rule p-3"
                >
                    <div class="mb-2 flex items-center justify-between">
                        <p class="text-14 text-ink">{{ day.label }}</p>
                        <button
                            type="button"
                            class="text-12 text-accent"
                            @click="addRange(person.id, day.value)"
                        >
                            Add range
                        </button>
                    </div>
                    <div
                        v-if="rangesFor(person.id, day.value).length === 0"
                        class="text-12 text-ink-2"
                    >
                        Closed
                    </div>
                    <div
                        v-for="(range, index) in rangesFor(person.id, day.value)"
                        :key="`${person.id}-${day.value}-${index}`"
                        class="mb-2 flex items-end gap-2"
                    >
                        <label class="flex-1 text-12 text-ink-2">
                            Starts
                            <input
                                type="time"
                                class="mt-1 w-full rounded border border-rule bg-white px-2 py-1 text-14"
                                :value="range.start_time"
                                @input="
                                    updateRange(
                                        person.id,
                                        day.value,
                                        index,
                                        'start_time',
                                        ($event.target as HTMLInputElement).value,
                                    )
                                "
                            />
                        </label>
                        <label class="flex-1 text-12 text-ink-2">
                            Ends
                            <input
                                type="time"
                                class="mt-1 w-full rounded border border-rule bg-white px-2 py-1 text-14"
                                :value="range.end_time"
                                @input="
                                    updateRange(
                                        person.id,
                                        day.value,
                                        index,
                                        'end_time',
                                        ($event.target as HTMLInputElement).value,
                                    )
                                "
                            />
                        </label>
                        <button
                            type="button"
                            class="min-h-tap text-12 text-danger"
                            @click="removeRange(person.id, day.value, index)"
                        >
                            Remove
                        </button>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
