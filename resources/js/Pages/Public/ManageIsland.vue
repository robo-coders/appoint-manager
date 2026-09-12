<script setup lang="ts">
import ProposalHeading from '@/Components/Public/ProposalHeading.vue';
import SlotPicker, { type Slot } from '@/Components/Public/SlotPicker.vue';
import Button from '@/Components/ui/Button.vue';
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import ToastContainer from '@/Components/ui/ToastContainer.vue';
import { toast } from '@/lib/toast';
import axios from 'axios';
import { computed, ref } from 'vue';

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
    tenant: { name: string; timezone: string; address: string; phone: string | null; code: string | null };
    state: 'live' | 'cancelled' | 'finished';
    horizon_days: number;
    can_cancel: boolean;
    can_reschedule: boolean;
    cancel_consequence: string;
    urls: { cancel: string; reschedule: string; availability: string };
}>();

const status = ref(props.booking.status);
const notice = ref('');
const confirming = ref(false);
const working = ref(false);
const finished = ref(props.state === 'finished');

const pickerOpen = ref(false);
const loadingDays = ref(false);
const days = ref<Record<string, Slot[]>>({});
const weekStart = ref(props.booking.starts_at_local.slice(0, 10));

const heading = ref({
    dayLabel: props.booking.day_label,
    time: props.booking.time,
    context: props.booking.context,
});

const cancelled = computed(() => status.value === 'cancelled' || props.state === 'cancelled');

const messageHref = computed(() => (props.tenant.phone ? `sms:${props.tenant.phone}` : null));

const noAvailability = computed(
    () =>
        !loadingDays.value &&
        Object.keys(days.value).length > 0 &&
        Object.values(days.value).every((slots) => slots.every((slot) => !slot.available)),
);

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

    try {
        const { data } = await axios.get(props.urls.availability, {
            params: { from: week.value[0], to: week.value[6] },
        });
        days.value = { ...days.value, ...(data.days ?? {}) };
    } catch {
        toast.error('Times didn’t load. Check your connection and try again.', {
            actionLabel: 'Try again',
            onAction: loadDays,
        });
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
    if (working.value) return;

    working.value = true;

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
        toast.success('Moved. We’ve sent you a new confirmation.');
        pickerOpen.value = false;
    } catch (err: unknown) {
        const taken = axios.isAxiosError(err) && err.response?.status === 409;
        const dead = axios.isAxiosError(err) && err.response?.status === 404;

        if (taken) {
            toast.error('That time has just gone. Here is what is still free.');
            await loadDays();
        } else if (dead) {
            toast.error('This booking link is no longer active.');
            finished.value = true;
        } else {
            toast.error('We couldn’t tell whether that went through. Nothing has been changed — try again.', {
                actionLabel: 'Try again',
                onAction: () => reschedule(slot),
            });
        }
    } finally {
        working.value = false;
    }
};

const cancel = async () => {
    if (working.value) return;

    working.value = true;

    try {
        const { data } = await axios.post(props.urls.cancel);
        status.value = data.status;
        notice.value = data.refund;
        confirming.value = false;
    } catch (err: unknown) {
        confirming.value = false;

        if (axios.isAxiosError(err) && err.response?.status === 404) {
            toast.error('This booking link is no longer active.');
            finished.value = true;
        } else {
            toast.error('We couldn’t tell whether that went through. The appointment is still booked — try again.', {
                actionLabel: 'Try again',
                onAction: cancel,
            });
        }
    } finally {
        working.value = false;
    }
};
</script>

<template>
    <div>
        <ToastContainer />

        <section v-if="finished && !cancelled" class="space-y-3">
            <h1 class="text-34 font-medium">That appointment has been</h1>
            <p class="text-15 text-ink-2">
                {{ heading.dayLabel }} at <span class="font-mono">{{ heading.time }}</span> is in the past, so there
                is nothing to change here.
            </p>
            <p class="text-15 text-ink-2">
                <template v-if="tenant.phone">
                    To book again, call {{ tenant.name }} on
                    <span class="font-mono">{{ tenant.phone }}</span>.
                </template>
                <template v-else>To book again, get in touch with {{ tenant.name }}.</template>
            </p>
        </section>

        <section v-else-if="cancelled" class="space-y-3">
            <h1 class="text-34 font-medium">Cancelled</h1>
            <p class="text-15 text-ink-2">
                {{ heading.dayLabel }} at <span class="font-mono">{{ heading.time }}</span> is no longer booked.
            </p>
            <p v-if="notice" class="text-15">{{ notice }}</p>
        </section>

        <template v-else-if="!finished">
            <SlotPicker
                v-if="pickerOpen"
                :week="week"
                :days="days"
                :selected-date="booking.starts_at_local.slice(0, 10)"
                :selected-starts-at="booking.starts_at"
                :loading="loadingDays || working"
                :context="heading.context"
                @pick-day="(iso) => (weekStart = iso)"
                @pick-slot="reschedule"
                @shift-week="shiftWeek"
            />

            <p v-if="pickerOpen && noAvailability" class="mt-6 text-15 text-ink-2">
                No availability in the next {{ horizon_days }} days.
                <template v-if="messageHref">
                    <a :href="messageHref" class="underline decoration-rule underline-offset-4">
                        Message {{ tenant.name }}
                    </a>
                    to find a time.
                </template>
                <template v-else>Get in touch with {{ tenant.name }} to find a time.</template>
            </p>

            <template v-else>
                <ProposalHeading
                    :context="heading.context"
                    :day-label="heading.dayLabel"
                    :time="heading.time"
                    :cost-line="booking.cost_line"
                />

                <p class="mt-3 text-13 text-ink-2">{{ tenant.address }}</p>

                <div v-if="can_reschedule" class="mt-6">
                    <Button variant="brand" block @click="openPicker">Move this appointment</Button>
                </div>

                <p v-if="booking.free_until" class="mt-3 text-center text-13 text-ink-2">
                    Free to cancel or move until {{ booking.free_until }}
                </p>

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

        <div class="mt-8 flex items-baseline justify-between gap-4 border-t border-t-rule pt-4">
            <span class="numeral text-12 text-ink-2">
                Ref {{ booking.public_token.slice(0, 8) }}
                <template v-if="tenant.code"> · {{ tenant.code }}</template>
            </span>
            <a
                v-if="messageHref"
                :href="messageHref"
                class="shrink-0 text-12 text-ink-2 underline decoration-rule underline-offset-4"
            >
                Message {{ tenant.name }}
            </a>
        </div>

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
