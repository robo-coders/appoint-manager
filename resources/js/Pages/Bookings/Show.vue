<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import DateTime from '@/Components/ui/DateTime.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { ref } from 'vue';
import type { Money } from '@/types/models';

const props = defineProps<{
    booking: {
        id: number;
        staff_name: string;
        service_name: string;
        customer_name: string;
        subject_name: string | null;
        starts_at_local: string;
        ends_at_local: string;
        status: string;
        deposit_status: string;
        source: string;
        price_at_booking: Money;
        deposit_at_booking: Money;
        public_token: string;
    };
    waitlist_matches: { count: number };
}>();

const confirm = ref<'notify' | 'silent' | null>(null);

const cancel = (offerWaitlist: boolean) => {
    router.delete(route('bookings.destroy', props.booking.id), {
        data: { offer_waitlist: offerWaitlist },
        onSuccess: () => (confirm.value = null),
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Booking" />
        <PageHeader :title="booking.customer_name" :description="booking.service_name" />
        <div class="max-w-lg space-y-2 rounded border border-rule bg-white p-6 text-14">
            <p>
                <DateTime :value="booking.starts_at_local" />
                –
                {{ booking.ends_at_local.slice(11) }}
            </p>
            <p class="text-ink-2">{{ booking.staff_name }}</p>
            <p v-if="booking.subject_name">{{ booking.subject_name }}</p>
            <p>Status: {{ booking.status }}</p>
            <p>Deposit: {{ booking.deposit_status }} ({{ booking.deposit_at_booking.formatted }})</p>
            <p>Total: {{ booking.price_at_booking.formatted }}</p>
            <p class="text-ink-2">Booked {{ booking.source === 'manual' ? 'in the diary' : 'online' }}</p>
            <div v-if="booking.status !== 'cancelled'" class="space-y-2 pt-4">
                <Button variant="danger" @click="confirm = 'notify'">Cancel booking</Button>
            </div>
            <Link :href="route('bookings.index')" class="inline-block pt-2 underline decoration-rule underline-offset-4">Back to bookings</Link>
        </div>

        <ConfirmDialog
            :show="confirm === 'notify'"
            title="Cancel this booking?"
            confirm-label="Cancel and text the waitlist"
            @close="confirm = null"
            @confirm="cancel(true)"
        >
            This frees the slot and texts {{ waitlist_matches.count }}
            {{ waitlist_matches.count === 1 ? 'person' : 'people' }} on the waitlist.
        </ConfirmDialog>
        <ConfirmDialog
            :show="confirm === 'silent'"
            title="Cancel without the waitlist?"
            confirm-label="Cancel only"
            @close="confirm = null"
            @confirm="cancel(false)"
        >
            The slot is freed. Nobody on the waitlist is contacted.
        </ConfirmDialog>
    </AppLayout>
</template>
