<script setup lang="ts">
import axios from 'axios';
import { ref } from 'vue';

interface Money {
    amount: number;
    formatted: string;
}

const props = defineProps<{
    booking: {
        public_token: string;
        service_name: string;
        staff_name: string;
        customer_name: string;
        starts_at_local: string;
        ends_at_local: string;
        status: string;
        deposit_status: string;
        price_at_booking: Money;
        deposit_at_booking: Money;
        starts_at: string;
    };
    tenant: { name: string; timezone: string; address: string };
    can_cancel: boolean;
    can_reschedule: boolean;
    refund_preview: string;
    urls: { cancel: string; reschedule: string; availability: string };
}>();

const error = ref('');
const notice = ref('');
const status = ref(props.booking.status);
const confirmCancel = ref(false);
const slots = ref<Array<{ starts_at: string; starts_at_local: string; staff_ids: number[] }>>([]);

const loadSlots = async () => {
    const from = props.booking.starts_at_local.slice(0, 10);
    const { data } = await axios.get(props.urls.availability, { params: { from, to: from } });
    slots.value = data.slots ?? [];
};

const cancel = async () => {
    error.value = '';
    try {
        const { data } = await axios.post(props.urls.cancel);
        status.value = data.status;
        notice.value = data.refund;
        confirmCancel.value = false;
    } catch {
        error.value = 'Could not cancel this booking.';
    }
};

const reschedule = async (startsAt: string, staffId: number) => {
    error.value = '';
    try {
        const { data } = await axios.post(props.urls.reschedule, { starts_at: startsAt, staff_id: staffId });
        notice.value = 'Moved to the new time.';
        status.value = data.status;
    } catch (err: unknown) {
        error.value = axios.isAxiosError(err) && err.response?.status === 409
            ? 'That time is no longer free.'
            : 'Could not move this booking.';
    }
};
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-20 font-medium">{{ booking.service_name }}</h1>
        <p class="text-14">{{ booking.starts_at_local }} – {{ booking.ends_at_local.slice(11) }}</p>
        <p class="text-14 text-ink-2">{{ tenant.address }}</p>
        <p class="text-14">Total {{ booking.price_at_booking.formatted }}. Deposit {{ booking.deposit_at_booking.formatted }}.</p>
        <p class="text-14">Status: {{ status }}</p>
        <p v-if="error" class="text-14 text-danger">{{ error }}</p>
        <p v-if="notice" class="text-14">{{ notice }}</p>

        <div v-if="can_cancel && status !== 'cancelled'" class="space-y-2 rounded border border-rule bg-white p-4">
            <p class="text-14">{{ refund_preview }}</p>
            <button v-if="!confirmCancel" type="button" class="text-14 text-danger" @click="confirmCancel = true">Cancel booking</button>
            <div v-else class="flex gap-2">
                <button type="button" class="rounded border border-danger px-3 py-2 text-14 text-danger" @click="cancel">Confirm cancel</button>
                <button type="button" class="text-14 text-ink-2" @click="confirmCancel = false">Keep it</button>
            </div>
        </div>

        <div v-if="can_reschedule && status !== 'cancelled'" class="space-y-2">
            <button type="button" class="text-14 underline" @click="loadSlots">Move this booking</button>
            <button
                v-for="slot in slots"
                :key="slot.starts_at"
                type="button"
                class="block w-full rounded border border-rule bg-white px-3 py-2 text-left text-14"
                @click="reschedule(slot.starts_at, slot.staff_ids[0])"
            >
                {{ slot.starts_at_local }}
            </button>
        </div>
    </div>
</template>
