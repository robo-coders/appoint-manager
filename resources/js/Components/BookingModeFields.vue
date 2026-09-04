<script setup lang="ts">
import Toggle from '@/Components/ui/Toggle.vue';

const bookingMode = defineModel<string>('bookingMode', { required: true });
const requestRequiresDeposit = defineModel<boolean>('requestRequiresDeposit', { required: true });

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
] as const;
</script>

<template>
    <fieldset class="space-y-3">
        <legend class="caption">How do you want to accept bookings?</legend>
        <label
            v-for="option in options"
            :key="option.value"
            class="flex min-h-row cursor-pointer items-start gap-3 border-b border-b-rule py-3"
        >
            <input
                v-model="bookingMode"
                type="radio"
                name="booking_mode"
                :value="option.value"
                class="mt-1 size-4 shrink-0 border-rule-strong text-ink"
            />
            <span>
                <span class="block text-13 text-ink">{{ option.label }}</span>
                <span class="mt-0.5 block text-12 text-ink-2">{{ option.hint }}</span>
            </span>
        </label>
        <Toggle
            v-if="bookingMode === 'request'"
            v-model="requestRequiresDeposit"
            class="pt-1"
            label="Require a deposit for requests?"
            hint="Hold a card until you confirm. Nothing is taken if you decline."
        />
    </fieldset>
</template>
