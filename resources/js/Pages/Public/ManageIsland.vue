<script setup lang="ts">
import ProposalHeading from '@/Components/Public/ProposalHeading.vue';
import SlotPicker, { type Slot } from '@/Components/Public/SlotPicker.vue';
import Button from '@/Components/ui/Button.vue';
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import axios from 'axios';
import { computed, ref } from 'vue';

/**
 * Managing a booking, in the same language as making one.
 *
 * This page and `OfferIsland` used to share nothing with `BookingIsland` — not
 * the layout, not the components, not the type scale, not the words. A customer
 * who booked on Monday and came back on Thursday to move it arrived somewhere
 * that did not look like the same business.
 *
 * So: the same dominant 34px statement of the appointment, the same single
 * column, the same picker, the same primary button. The one thing that differs
 * is what the page is *for*, and that is carried by the actions underneath.
 *
 * **The consequence is stated before the confirm, not after.** "Cancel and
 * refund £10" and "Cancel — the £10 deposit is not refunded this close to the
 * appointment" are two different decisions, and which one a customer is making
 * has to be legible on the button they are about to press. A confirm dialog
 * that says "Are you sure?" moves that information to the wrong side of the
 * decision.
 */

type Money = { amount: number; formatted: string; currency: string };

const props = defineProps<{
    booking: {
        public_token: string;
        service_name: string;
        staff_name: string;
        customer_name: string;
        subject_name: string | null;
        starts_at: string;
        starts_at_local: string;
        ends_at_local: string;
        status: string;
        deposit_status: string;
        price_at_booking: Money;
        deposit_at_booking: Money;
        duration_minutes: number | null;
        day_label: string;
        time: string;
        cost_line: string;
        free_until: string | null;
        context: string;
    };
    tenant: { name: string; timezone: string; address: string; phone: string | null };
    can_cancel: boolean;
    can_reschedule: boolean;
    /** Already a sentence, and already the *consequence* rather than a policy. */
    cancel_consequence: string;
    urls: { cancel: string; reschedule: string; availability: string };
}>();

const status = ref(props.booking.status);
const error = ref('');
const notice = ref('');
const confirming = ref(false);
const working = ref(false);

const pickerOpen = ref(false);
const loadingDays = ref(false);
const days = ref<Record<string, Slot[]>>({});
const weekStart = ref(props.booking.starts_at_local.slice(0, 10));

const heading = ref({
    dayLabel: props.booking.day_label,
    time: props.booking.time,
    context: props.booking.context,
});

const cancelled = computed(() => status.value === 'cancelled');

const shiftDays = (iso: string, amount: number) => {
    const [y, m, d] = iso.split('-').map(Number);
    const next = new Date(y, m - 1, d + amount);

    return [next.getFullYear(), String(next.getMonth() + 1).padStart(2, '0'), String(next.getDate()).padStart(2, '0')].join('-');
};

const week = computed(() => {
    const [y, m, d] = weekStart.value.split('-').map(Number);
    const offset = (new Date(y, m - 1, d).getDay() + 6) % 7;
    const monday = shiftDays(weekStart.value, -offset);

    return Array.from({ length: 7 }, (_, i) => shiftDays(monday, i));
});

const loadDays = async () => {
    loadingDays.value = true;
    error.value = '';

    try {
        const { data } = await axios.get(props.urls.availability, {
            params: { from: week.value[0], to: week.value[6] },
        });
        days.value = { ...days.value, ...(data.days ?? {}) };
    } catch {
        error.value = 'Times didn’t load. Check your connection and try again.';
    } finally {
        loadingDays.value = false;
    }
};

const openPicker = async () => {
    pickerOpen.value = true;
    await loadDays();
};

const shiftWeek = async (direction: number) => {
    weekStart.value = shiftDays(week.value[0], direction * 7);
    await loadDays();
};

const reschedule = async (slot: Slot) => {
    working.value = true;
    error.value = '';

    try {
        await axios.post(props.urls.reschedule, {
            starts_at: slot.starts_at,
            staff_id: slot.staff_ids[0],
        });

        const local = new Date(slot.starts_at);
        heading.value = {
            dayLabel: local.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' }),
            time: slot.starts_at_local,
            context: props.booking.context,
        };
        notice.value = 'Moved. We’ve sent you a new confirmation.';
        pickerOpen.value = false;
    } catch (err: unknown) {
        error.value =
            axios.isAxiosError(err) && err.response?.status === 409
                ? 'That time has just gone. Here is what is still free.'
                : 'We couldn’t move this appointment.';
    } finally {
        working.value = false;
    }
};

const cancel = async () => {
    working.value = true;
    error.value = '';

    try {
        const { data } = await axios.post(props.urls.cancel);
        status.value = data.status;
        notice.value = data.refund;
        confirming.value = false;
    } catch {
        error.value = 'We couldn’t cancel this appointment.';
        confirming.value = false;
    } finally {
        working.value = false;
    }
};
</script>

<template>
    <div>
        <p v-if="error" class="mb-4 text-15 text-danger" role="alert">{{ error }}</p>

        <!-- ============================================================
             Cancelled. One statement, no controls: there is nothing left
             to do here and a row of dead buttons says otherwise.
             ============================================================ -->
        <section v-if="cancelled" class="space-y-3">
            <h1 class="text-34 font-medium">Cancelled</h1>
            <p class="text-15 text-ink-2">
                {{ heading.dayLabel }} at <span class="font-mono">{{ heading.time }}</span> is no longer booked.
            </p>
            <p v-if="notice" class="text-15">{{ notice }}</p>
        </section>

        <template v-else>
            <SlotPicker
                v-if="pickerOpen"
                :week="week"
                :days="days"
                :selected-date="booking.starts_at_local.slice(0, 10)"
                :selected-starts-at="booking.starts_at"
                :loading="loadingDays"
                :context="heading.context"
                @pick-day="(iso) => (weekStart = iso)"
                @pick-slot="reschedule"
                @shift-week="shiftWeek"
            />

            <template v-else>
                <!-- The same 34px statement as the booking page. Same
                     appointment, same salon, same product. -->
                <ProposalHeading
                    :context="heading.context"
                    :day-label="heading.dayLabel"
                    :time="heading.time"
                    :cost-line="booking.cost_line"
                />

                <p v-if="notice" class="mt-4 text-15" role="status">{{ notice }}</p>

                <p class="mt-3 text-13 text-ink-2">{{ tenant.address }}</p>

                <div v-if="can_reschedule" class="mt-6">
                    <Button variant="brand" block @click="openPicker">Move this appointment</Button>
                </div>

                <p v-if="booking.free_until" class="mt-3 text-center text-13 text-ink-2">
                    Free to cancel or move until {{ booking.free_until }}
                </p>

                <!--
                    Why the move button is not here. A page that simply omits a
                    control leaves a customer wondering whether they missed it;
                    the honest version says the appointment is too close and
                    points at the thing that does still work.
                -->
                <p v-else-if="!can_reschedule" class="mt-6 text-15 text-ink-2">
                    This is too close to the appointment to move online.
                    <template v-if="tenant.phone">
                        Call {{ tenant.name }} on <span class="font-mono">{{ tenant.phone }}</span> and they will sort it.
                    </template>
                    <template v-else>Call {{ tenant.name }} and they will sort it.</template>
                </p>
            </template>

            <template v-if="!pickerOpen && can_cancel">
                <div class="mt-8 flex items-center gap-3" aria-hidden="true">
                    <span class="block flex-1 border-t border-t-rule"></span>
                    <span class="text-13 text-ink-2">Or</span>
                    <span class="block flex-1 border-t border-t-rule"></span>
                </div>

                <ul class="mt-2">
                    <li>
                        <!--
                            The row says what will happen, not what the button
                            is called. "Cancel and refund £10" and "Cancel — the
                            £10 deposit is not refunded this close to the
                            appointment" are different decisions, and the
                            difference belongs *here*, before the tap.
                        -->
                        <ChoiceRow :label="cancel_consequence" @pick="confirming = true" />
                    </li>
                </ul>
            </template>

            <p v-if="pickerOpen" class="mt-6 text-center">
                <QuietAction @click="pickerOpen = false">
                    Keep {{ heading.dayLabel }} at <span class="font-mono">{{ heading.time }}</span>
                </QuietAction>
            </p>
        </template>

        <ConfirmDialog
            :show="confirming"
            title="Cancel this appointment?"
            :body="cancel_consequence"
            confirm-label="Yes, cancel it"
            cancel-label="Keep it"
            :loading="working"
            @close="confirming = false"
            @confirm="cancel"
        />
    </div>
</template>
