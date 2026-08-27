<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { weekdays } from '@/lib/weekdays';
import type { AvailabilityRange } from '@/types/models';

/**
 * A week of opening hours for one person.
 *
 * Seven cards, one per day, each holding zero or more ranges. A day with no
 * ranges says `Closed` rather than showing an empty box, which is the same rule
 * the booking page's picker follows: the absence of something has to be stated,
 * not implied by a gap.
 *
 * Every control in here is a library one now. The time fields were bare inputs
 * with a hand-written label above them and no error binding at all — so a
 * rejected range came back with no explanation attached to anything.
 */

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
                <div v-for="day in weekdays" :key="`${person.id}-${day.value}`" class="rounded border border-rule p-3">
                    <div class="mb-2 flex items-center justify-between gap-2">
                        <p class="text-14 text-ink">{{ day.label }}</p>
                        <QuietAction @click="addRange(person.id, day.value)">Add hours</QuietAction>
                    </div>

                    <p v-if="rangesFor(person.id, day.value).length === 0" class="text-12 text-ink-2">Closed</p>

                    <!--
                        `flex-wrap`, and it is a fix rather than a precaution.

                        Two `type="time"` fields have an intrinsic minimum width
                        the browser sets — the digits plus the picker glyph, about
                        120px in Chrome — and `flex-1` cannot shrink a field below
                        it. Add a text button and the row measures wider than the
                        card it is in, at *both* widths that matter: 38px past the
                        viewport at 375, and past the edge of each column of the
                        `md:grid-cols-2` grid at 768, where "Remove" rendered as
                        "Rem". The page itself scrolled sideways, which is the one
                        thing a phone layout must never do.

                        Wrapping lets Remove drop to its own line when the fields
                        have taken the width, and `ml-auto` keeps it under the
                        right-hand edge of the field it belongs to rather than
                        floating out on the left.

                        The wrappers used to carry `min-w-0`, so the fields would
                        give up their spare width before Remove wrapped. That
                        traded one clipping for another: `type="time"` in a
                        12-hour locale renders "05:00 PM", and at 375 inside the
                        onboarding column each field got about 114px against an
                        intrinsic 120 — so the meridiem was cut off and a salon
                        closing at five in the afternoon read as closing at five
                        in the morning. A wrapped Remove is a layout decision; a
                        missing PM is a wrong time. `min-w-0` is gone and the row
                        wraps when the fields need their own width.
                    -->
                    <div
                        v-for="(range, index) in rangesFor(person.id, day.value)"
                        :key="`${person.id}-${day.value}-${index}`"
                        class="mb-2 flex flex-wrap items-end gap-2"
                    >
                        <div class="flex-1">
                            <TextInput
                                :model-value="range.start_time"
                                type="time"
                                label="Opens"
                                @update:model-value="
                                    updateRange(person.id, day.value, index, 'start_time', String($event))
                                "
                            />
                        </div>
                        <div class="flex-1">
                            <TextInput
                                :model-value="range.end_time"
                                type="time"
                                label="Closes"
                                @update:model-value="updateRange(person.id, day.value, index, 'end_time', String($event))"
                            />
                        </div>
                        <Button
                            variant="ghost"
                            class="ml-auto shrink-0"
                            :aria-label="`Remove ${day.label} ${range.start_time} to ${range.end_time}`"
                            @click="removeRange(person.id, day.value, index)"
                        >
                            Remove
                        </Button>
                    </div>
                </div>
            </div>
        </section>
    </div>
</template>
