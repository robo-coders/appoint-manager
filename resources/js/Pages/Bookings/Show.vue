<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import DateTime from '@/Components/ui/DateTime.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, Link, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
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
        is_loyalty_reward: boolean;
        starts_at: string;
        public_token: string;
    };
    waitlist_matches: { count: number };
}>();

const confirm = ref<'notify' | 'silent' | null>(null);
const completing = ref(false);
const markingNoShow = ref(false);

/*
 * "Mark as done" and "Mark as no show" only where they mean something: a
 * confirmed appointment whose start time has passed. A pending request has not
 * been accepted, a cancellation did not happen, and an appointment on Thursday
 * has neither happened nor been missed yet — the server refuses all three for
 * both actions, and a button that is only ever refused is a button that should
 * not be drawn.
 *
 * One computed for both because the two are the same question with two answers:
 * the appointment is over, and the owner is saying which way it went.
 */
const completable = computed(
    () => props.booking.status === 'confirmed' && new Date(props.booking.starts_at) <= new Date(),
);

const complete = () => {
    completing.value = true;
    router.post(route('bookings.complete', props.booking.id), {}, {
        preserveScroll: true,
        onFinish: () => (completing.value = false),
    });
};

const markNoShow = () => {
    markingNoShow.value = true;
    router.post(route('bookings.no-show', props.booking.id), {}, {
        preserveScroll: true,
        onFinish: () => (markingNoShow.value = false),
    });
};

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
            <p>
                Total: {{ booking.price_at_booking.formatted }}
                <!--
                    Why a £0 appointment is £0. Without this the free one reads
                    as a pricing mistake.
                -->
                <span v-if="booking.is_loyalty_reward" class="text-ink-2">· loyalty reward</span>
            </p>
            <p class="text-ink-2">Booked {{ booking.source === 'manual' ? 'in the diary' : 'online' }}</p>
            <div v-if="booking.status !== 'cancelled'" class="flex flex-wrap items-center gap-3 pt-4">
                <!--
                    The only writer of `BookingStatus::Completed` in the app. It
                    was read in four places and set by nothing but the demo
                    seeders — see `BookingService::complete`.
                -->
                <Button v-if="completable" :loading="completing" @click="complete">Mark as done</Button>
                <!--
                    The only writer of `BookingStatus::NoShow`. The dashboard's
                    no-show rate has read it since launch and nothing could set
                    it, so the stat was structurally zero — see
                    `BookingService::markNoShow`.
                -->
                <Button v-if="completable" variant="secondary" :loading="markingNoShow" @click="markNoShow">
                    Mark as no show
                </Button>
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
