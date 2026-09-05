<script setup lang="ts">
import RadioGroup from '@/Components/ui/RadioGroup.vue';
import Toggle from '@/Components/ui/Toggle.vue';

/**
 * Automated bookings or requests, and the deposit question that only applies to
 * the second.
 *
 * The two radios were hand-rolled here — a bare `<input type="radio">` with
 * `size-4 border-rule-strong text-ink` written out, plus its own two-line label
 * markup — which is what kept `check:components` failing after every screen had
 * been rebuilt. They are `ui/RadioGroup` now, which owns that markup once; see
 * that file for why a radio group and not a select, a toggle or a `ChoiceRow`.
 *
 * `name="booking_mode"` is kept explicitly rather than left to the generated
 * default: the value posts under that key and it reads as the field's name in
 * the DOM, which is what a person debugging a saved setting looks for.
 */
const bookingMode = defineModel<string>('bookingMode', { required: true });
const requestRequiresDeposit = defineModel<boolean>('requestRequiresDeposit', { required: true });

defineProps<{ error?: string }>();

const options = [
    {
        value: 'automated',
        label: 'Automated',
        hint: 'The slot is theirs as soon as they book.',
    },
    {
        value: 'request',
        label: 'Requests',
        hint: 'They ask for a time. You confirm or decline before it is theirs.',
    },
];
</script>

<template>
    <RadioGroup
        v-model="bookingMode"
        legend="How do you want to accept bookings?"
        name="booking_mode"
        :options="options"
        :error="error"
    >
        <Toggle
            v-if="bookingMode === 'request'"
            v-model="requestRequiresDeposit"
            class="pt-1"
            label="Require a deposit for requests?"
            hint="Hold a card until you confirm. Nothing is taken if you decline."
        />
    </RadioGroup>
</template>
