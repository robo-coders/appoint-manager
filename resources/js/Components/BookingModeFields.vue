<script setup lang="ts">
import RadioGroup from '@/Components/ui/RadioGroup.vue';
import Toggle from '@/Components/ui/Toggle.vue';

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
