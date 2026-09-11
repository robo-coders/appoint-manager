<script setup lang="ts">
import ProposalHeading from '@/Components/Public/ProposalHeading.vue';
import ServiceChoiceList from '@/Components/Public/ServiceChoiceList.vue';
import SlotPicker, { type Slot } from '@/Components/Public/SlotPicker.vue';
import Button from '@/Components/ui/Button.vue';
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import { sentenceCase } from '@/lib/copy';
import axios from 'axios';
import { computed, nextTick, reactive, ref } from 'vue';

type Money = { amount: number; formatted: string; currency: string };

type ProposalPayload = {
    starts_at: string;
    date: string;
    day: string;
    time: string;
    ends_time: string;
    service_id: number;
    service_name: string;
    duration_minutes: number;
    price: Money;
    deposit: Money;
    staff_id: number;
    staff_name: string;
    staff_first_name: string;
    staff_ids: number[];
    subject_id: number | null;
    subject_name: string | null;
    reason: string;
    reason_key: string;
    day_label: string;
    cost_line: string;
    free_until: string | null;
    action_label: string;
    meta: string;
};

interface SubjectField {
    key: string;
    label: string;
    type: string;
    required?: boolean;
    options?: string[];
}

const props = defineProps<{
    tenant: {
        name: string;
        slug: string;
        timezone: string;
        currency: string;
        takes_deposits: boolean;
        booking_mode: 'automated' | 'request';
        request_requires_deposit: boolean;
        request_sent_message: string;
        phone?: string | null;
    };
    stripePublishableKey?: string | null;
    services: Array<{ id: number; name: string; duration_minutes: number; price: Money; deposit_amount: Money }>;
    suggestion: {
        primary: ProposalPayload | null;
        alternatives: ProposalPayload[];
        returning: boolean;
        customer_name: string | null;
        subject_name: string | null;
        interval_days: number | null;
        context: string | null;
        timezone: string;
        state?: 'proposal' | 'fully_booked' | 'setup_incomplete';
        setup_reason?: 'no_service' | 'no_staff' | 'no_staff_for_service' | null;
        setup_heading?: string | null;
        setup_note?: string | null;
    };
    vertical: { subject_singular: string; subject_fields: SubjectField[]; appointment_singular: string };
    today: string;
    urls: { page: string; availability: string; store: string; waitlist: string };
}>();

const proposal = ref<ProposalPayload | null>(props.suggestion.primary);
const alternatives = ref<ProposalPayload[]>(props.suggestion.alternatives);
const context = ref(props.suggestion.context ?? '');

const pickerOpen = ref(false);
const detailsOpen = ref(false);
const servicesOpen = ref(false);
const waitlistSaved = ref(false);
const waitlistMessage = ref('');

const error = ref('');
const submitting = ref(false);
const booked = ref(false);
const requested = ref(false);

const setupIncomplete = computed(() => props.suggestion.state === 'setup_incomplete');
const setupHeading = computed(
    () => props.suggestion.setup_heading || `${props.tenant.name} is not taking online bookings yet`,
);
const messageHref = computed(() => (props.tenant.phone ? `sms:${props.tenant.phone}` : null));

const isRequestMode = computed(() => props.tenant.booking_mode === 'request');
const requestAction = 'Request this time';

const notice = ref('');

const weekStart = ref(props.suggestion.primary?.date ?? props.today);
const days = ref<Record<string, Slot[]>>({});
const loadingDays = ref(false);
const serviceId = ref<number | null>(props.suggestion.primary?.service_id ?? props.services[0]?.id ?? null);

const details = reactive({
    name: '',
    email: '',
    phone: '',
    subject_name: '',
    subject_attributes: Object.fromEntries(props.vertical.subject_fields.map((f) => [f.key, ''])) as Record<string, string>,
});
const fieldErrors = reactive<Record<string, string>>({});

const clientSecret = ref('');
const stripeAccount = ref('');
const manageUrl = ref('');
const paying = ref(false);

const firstField = ref<InstanceType<typeof TextInput> | null>(null);

const shiftDays = (iso: string, amount: number) => {
    const [y, m, d] = iso.split('-').map(Number);
    const next = new Date(y, m - 1, d + amount);

    return [next.getFullYear(), String(next.getMonth() + 1).padStart(2, '0'), String(next.getDate()).padStart(2, '0')].join('-');
};

const week = computed(() => {
    const [y, m, d] = weekStart.value.split('-').map(Number);
    const anchor = new Date(y, m - 1, d);
    const offset = (anchor.getDay() + 6) % 7;
    const monday = shiftDays(weekStart.value, -offset);

    return Array.from({ length: 7 }, (_, i) => shiftDays(monday, i));
});

const loadDays = async () => {
    if (serviceId.value === null) return;

    loadingDays.value = true;
    error.value = '';

    try {
        const { data } = await axios.get(props.urls.availability, {
            params: { service: serviceId.value, from: week.value[0], to: week.value[6] },
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
    detailsOpen.value = false;
    await loadDays();
};

const clearNotice = () => (notice.value = '');

const shiftWeek = async (direction: number) => {
    weekStart.value = shiftDays(week.value[0], direction * 7);
    await loadDays();
};

const pickDay = (iso: string) => {
    weekStart.value = iso;
};

const pickSlot = (slot: Slot) => {
    const base = proposal.value;
    if (!base) return;

    clearNotice();

    const local = new Date(`${slot.starts_at}`);
    const dayLabel = local.toLocaleDateString(undefined, { weekday: 'long', day: 'numeric', month: 'long' });

    proposal.value = {
        ...base,
        starts_at: slot.starts_at,
        date: slot.starts_at.slice(0, 10),
        time: slot.starts_at_local,
        day_label: dayLabel,
        staff_id: slot.staff_ids[0] ?? base.staff_id,
        staff_ids: slot.staff_ids,
        reason: 'You chose this time',
        reason_key: 'chosen',
        action_label: isRequestMode.value
            ? requestAction
            : `Reserve ${local.toLocaleDateString(undefined, { weekday: 'long' })} at ${slot.starts_at_local}`,
        free_until: null,
    };

    context.value = ['You chose this time', ...(props.suggestion.context ?? '').split(' · ').slice(1)].join(' · ');
    pickerOpen.value = false;
};

const switchService = (id: number) => {
    window.location.href = `${props.urls.page}?service=${id}`;
};

const staffChange = (alternative: ProposalPayload): string | undefined => {
    const current = proposal.value;

    if (!current || alternative.staff_id === current.staff_id) return undefined;

    return `with ${alternative.staff_first_name} instead of ${current.staff_first_name}`;
};

const acceptAlternative = (alternative: ProposalPayload) => {
    clearNotice();
    proposal.value = alternative;
    context.value = [alternative.reason, ...(props.suggestion.context ?? '').split(' · ').slice(1)].join(' · ');
    alternatives.value = [
        ...(props.suggestion.primary ? [props.suggestion.primary] : []),
        ...props.suggestion.alternatives.filter((a) => a.starts_at !== alternative.starts_at),
    ].slice(0, 3);
    pickerOpen.value = false;
};

const validate = () => {
    Object.keys(fieldErrors).forEach((key) => delete fieldErrors[key]);

    if (!details.name.trim()) fieldErrors.name = 'We need a name for the booking.';
    if (!details.email.trim()) fieldErrors.email = 'We send the confirmation here.';
    if (!details.phone.trim()) fieldErrors.phone = 'We text you if anything changes.';
    if (!details.subject_name.trim()) {
        fieldErrors.subject_name = `Who is the ${props.vertical.appointment_singular} for?`;
    }

    for (const field of props.vertical.subject_fields) {
        if (field.required && !details.subject_attributes[field.key]) {
            fieldErrors[field.key] = `Please add ${field.label.toLowerCase()}.`;
        }
    }

    return Object.keys(fieldErrors).length === 0;
};

const reserve = async () => {
    if (!proposal.value) return;

    if (!props.suggestion.returning && !detailsOpen.value) {
        detailsOpen.value = true;
        await nextTick();
        firstField.value?.focus();

        return;
    }

    if (!props.suggestion.returning && !validate()) {
        await nextTick();
        document.querySelector<HTMLElement>('[aria-invalid="true"]')?.focus();

        return;
    }

    submitting.value = true;
    error.value = '';

    try {
        const { data } = await axios.post(props.urls.store, {
            service_id: proposal.value.service_id,
            starts_at: proposal.value.starts_at,
            staff_id: proposal.value.staff_id,
            name: details.name || props.suggestion.customer_name || '',
            email: details.email,
            phone: details.phone,
            subject_id: proposal.value.subject_id,
            subject_name: proposal.value.subject_id ? null : details.subject_name,
            subject_attributes: proposal.value.subject_id ? {} : details.subject_attributes,
        });

        manageUrl.value = data.booking.manage_url;

        if (data.payment?.client_secret) {
            clientSecret.value = data.payment.client_secret;
            stripeAccount.value = data.payment.connected_account;
            await nextTick();
            await mountCard();

            return;
        }

        if (data.booking.status === 'pending' && isRequestMode.value) {
            requested.value = true;

            return;
        }

        if (data.booking.status !== 'confirmed') {
            error.value = 'We couldn’t set up payment. Nothing has been charged — please try again in a moment.';

            return;
        }

        booked.value = true;
    } catch (err: unknown) {
        const status = axios.isAxiosError(err) ? err.response?.status : 0;
        const message = axios.isAxiosError(err) ? err.response?.data?.message : null;

        if (status === 409) {
            await openPicker();
            notice.value = 'That time was just taken. Here is what is still free.';
        } else if (status === 503) {
            error.value = message ?? 'We couldn’t reach payments. Nothing has been charged — please try again in a moment.';
        } else if (status === 422) {
            const bag = axios.isAxiosError(err) ? err.response?.data?.errors : null;
            if (bag) {
                Object.entries(bag as Record<string, string[]>).forEach(([key, list]) => {
                    fieldErrors[key] = list[0];
                });
                detailsOpen.value = true;
            } else {
                error.value = message ?? 'Please check your details and try again.';
            }
        } else {
            error.value = 'We couldn’t finish this booking. Check your details and try again.';
        }
    } finally {
        submitting.value = false;
    }
};

const loadStripeJs = async () => {
    if ((window as unknown as { Stripe?: unknown }).Stripe) return;

    await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Could not load payments.'));
        document.head.appendChild(script);
    });
};

const mountCard = async () => {
    try {
        await loadStripeJs();
    } catch {
        error.value = 'Payments didn’t load. Check your connection and try again.';

        return;
    }

    const StripeCtor = (window as unknown as { Stripe?: (key: string, opts?: { stripeAccount?: string }) => any }).Stripe;
    if (!StripeCtor || !props.stripePublishableKey) return;

    const stripe = StripeCtor(props.stripePublishableKey, { stripeAccount: stripeAccount.value || undefined });
    const card = stripe.elements().create('card');
    card.mount('#card-element');
    (window as unknown as { __amCard: unknown; __amStripe: unknown }).__amCard = card;
    (window as unknown as { __amStripe: unknown }).__amStripe = stripe;
};

const confirmPay = async () => {
    paying.value = true;
    error.value = '';

    const stripe = (window as unknown as { __amStripe?: { confirmCardPayment: Function } }).__amStripe;
    const card = (window as unknown as { __amCard?: unknown }).__amCard;

    if (!stripe || !card) {
        error.value = 'The payment form is not ready.';
        paying.value = false;

        return;
    }

    const result = await stripe.confirmCardPayment(clientSecret.value, { payment_method: { card } });

    if (result.error) {
        error.value = result.error.message ?? 'Payment failed.';
        paying.value = false;

        return;
    }

    if (isRequestMode.value) {
        clientSecret.value = '';
        requested.value = true;
        paying.value = false;

        return;
    }

    window.location.href = manageUrl.value;
};

const joinWaitlist = async () => {
    error.value = '';

    try {
        const { data } = await axios.post(props.urls.waitlist, {
            service_id: serviceId.value,
            name: details.name || props.suggestion.customer_name || 'Waiting',
            email: details.email,
            phone: details.phone,
            preferred_days: [],
            preferred_times: 'any',
        });
        waitlistMessage.value = data?.message ?? 'Done. We’ll text you as soon as a slot opens.';
        waitlistSaved.value = true;
    } catch {
        error.value = 'We couldn’t add you to the waitlist. Please try again.';
    }
};
</script>

<template>
    <div>
        <p v-if="error" class="mb-4 text-15 text-danger" role="alert">{{ error }}</p>

        <p v-if="notice" class="mb-4 text-15" role="status">{{ notice }}</p>

        <section v-if="clientSecret" class="space-y-4">
            <h1 class="text-20 font-medium">{{ isRequestMode ? 'Hold the deposit' : 'Pay the deposit' }}</h1>
            <p class="text-15 text-ink-2">
                {{ proposal?.cost_line }}.
                {{
                    isRequestMode
                        ? 'Your card is held, not charged, until they confirm.'
                        : 'The appointment is held for 15 minutes while you pay.'
                }}
            </p>
            <div id="card-element" class="rounded border border-rule bg-white p-3"></div>
            <Button variant="brand" block :loading="paying" @click="confirmPay">
                {{ isRequestMode ? 'Hold and send request' : 'Pay now' }}
            </Button>
        </section>

        <section v-else-if="requested" class="space-y-3">
            <h1 class="text-34 font-medium">Request sent</h1>
            <p class="text-15 text-ink-2">
                {{ tenant.request_sent_message }}
            </p>
            <p v-if="proposal" class="text-15 text-ink-2">
                {{ proposal.day_label }} at <span class="font-mono">{{ proposal.time }}</span>
            </p>
        </section>

        <section v-else-if="booked" class="space-y-3">
            <h1 class="text-34 font-medium">You’re booked</h1>
            <p class="text-15 text-ink-2">
                {{ proposal?.day_label }} at <span class="font-mono">{{ proposal?.time }}</span
                >. We’ve sent a confirmation you can cancel or move from.
            </p>
            <p v-if="manageUrl" class="pt-2">
                <a
                    :href="manageUrl"
                    class="min-h-tap text-15 underline decoration-rule underline-offset-4 transition duration-fast ease-product hover:decoration-ink"
                >
                    Manage this appointment
                </a>
            </p>
        </section>

        <section v-else-if="setupIncomplete" class="space-y-4">
            <h1 class="text-24 font-medium">{{ setupHeading }}</h1>
            <p class="text-15 text-ink-2">{{ suggestion.setup_note }}</p>
            <p class="text-15 text-ink-2">
                <template v-if="messageHref">
                    <a :href="messageHref" class="min-h-tap underline decoration-rule underline-offset-4">
                        Message {{ tenant.name }}
                    </a>
                    to book.
                </template>
                <template v-else>Get in touch with {{ tenant.name }} to book.</template>
            </p>
        </section>

        <section v-else-if="!proposal" class="space-y-4">
            <h1 class="text-24 font-medium">{{ tenant.name }} is fully booked</h1>
            <p class="text-15 text-ink-2">
                There is nothing free in the diary at the moment. Leave your number and we will text
                you the moment something opens up — which happens more often than you would think.
            </p>

            <p v-if="waitlistSaved" class="text-15">
                {{ waitlistMessage }}
            </p>
            <div v-else class="space-y-3">
                <TextInput v-model="details.name" label="Your name" autocomplete="name" />
                <TextInput v-model="details.email" label="Email" type="email" autocomplete="email" />
                <TextInput v-model="details.phone" label="Mobile" type="tel" autocomplete="tel" />
                <Button variant="brand" block @click="joinWaitlist">Text me when something opens</Button>
            </div>
        </section>

        <template v-else>
            <template v-if="pickerOpen">
                <SlotPicker
                    :week="week"
                    :days="days"
                    :selected-date="proposal.date"
                    :selected-starts-at="proposal.starts_at"
                    :loading="loadingDays"
                    :context="context"
                    @pick-day="pickDay"
                    @pick-slot="pickSlot"
                    @shift-week="shiftWeek"
                />

                <ServiceChoiceList
                    v-if="services.length > 1"
                    class="mt-8"
                    heading="Something else"
                    :services="services"
                    :current-id="proposal.service_id"
                    @pick="switchService"
                />

                <p class="mt-6 text-center">
                    <QuietAction @click="pickerOpen = false">
                        Back to {{ proposal.day_label }} at <span class="font-mono">{{ proposal.time }}</span>
                    </QuietAction>
                </p>
            </template>

            <template v-else>
                <ProposalHeading
                    :context="context"
                    :day-label="proposal.day_label"
                    :time="proposal.time"
                    :cost-line="proposal.cost_line"
                />

                <div class="mt-6">
                    <Button variant="brand" block :loading="submitting" @click="reserve">
                        {{ proposal.action_label }}
                    </Button>
                </div>

                <p v-if="proposal.free_until" class="mt-3 text-center text-13 text-ink-2">
                    Free to cancel or move until {{ proposal.free_until }}
                </p>
                <p v-else-if="tenant.takes_deposits && proposal.deposit.amount > 0" class="mt-3 text-center text-13 text-ink-2">
                    This is inside the cancellation window, so the deposit is not refundable
                </p>

                <section v-if="detailsOpen" class="appear mt-8 space-y-3">
                    <h2 class="caption">Just your details, and it’s yours</h2>
                    <TextInput
                        ref="firstField"
                        v-model="details.name"
                        label="Your name"
                        autocomplete="name"
                        :error="fieldErrors.name"
                    />
                    <TextInput
                        v-model="details.email"
                        label="Email"
                        type="email"
                        autocomplete="email"
                        hint="Your confirmation, and the link to change this."
                        :error="fieldErrors.email"
                    />
                    <TextInput
                        v-model="details.phone"
                        label="Mobile"
                        type="tel"
                        autocomplete="tel"
                        :error="fieldErrors.phone"
                    />
                    <TextInput
                        v-model="details.subject_name"
                        :label="`${sentenceCase(vertical.subject_singular)} name`"
                        :error="fieldErrors.subject_name"
                    />
                    <template v-for="field in vertical.subject_fields" :key="field.key">
                        <Select
                            v-if="field.type === 'select'"
                            v-model="details.subject_attributes[field.key]"
                            :label="field.label"
                            :error="fieldErrors[field.key]"
                            :options="(field.options ?? []).map((o) => ({ value: o, label: o }))"
                        />
                        <Textarea
                            v-else-if="field.type === 'textarea'"
                            v-model="details.subject_attributes[field.key]"
                            :label="field.label"
                            :rows="3"
                            :error="fieldErrors[field.key]"
                        />
                        <TextInput
                            v-else
                            v-model="details.subject_attributes[field.key]"
                            :label="field.label"
                            :error="fieldErrors[field.key]"
                        />
                    </template>
                </section>

                <div v-if="alternatives.length" class="mt-8 flex items-center gap-3" aria-hidden="true">
                    <span class="block flex-1 border-t border-t-rule"></span>
                    <span class="text-13 text-ink-2">Or</span>
                    <span class="block flex-1 border-t border-t-rule"></span>
                </div>

                <template v-if="alternatives.length">
                    <h2 class="sr-only">Other times</h2>
                    <ul class="mt-2">
                        <li v-for="alternative in alternatives" :key="alternative.starts_at">
                            <ChoiceRow
                                :label="alternative.reason"
                                :note="staffChange(alternative)"
                                :meta="alternative.meta"
                                @pick="acceptAlternative(alternative)"
                            />
                        </li>
                    </ul>
                </template>

                <ServiceChoiceList
                    v-if="servicesOpen && services.length > 1"
                    id="service-list"
                    class="appear mt-8"
                    heading="A different service"
                    :services="services"
                    :current-id="proposal.service_id"
                    @pick="switchService"
                />

                <p class="mt-6 flex flex-wrap items-center justify-center">
                    <QuietAction @click="openPicker">Pick another day</QuietAction>
                    <template v-if="services.length > 1">
                        <span class="text-13 text-ink-2" aria-hidden="true">·</span>
                        <QuietAction
                            aria-controls="service-list"
                            :aria-expanded="servicesOpen"
                            @click="servicesOpen = !servicesOpen"
                        >
                            A different service
                        </QuietAction>
                    </template>
                </p>
            </template>
        </template>
    </div>
</template>
