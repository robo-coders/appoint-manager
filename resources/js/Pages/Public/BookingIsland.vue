<script setup lang="ts">
import ProposalHeading from '@/Components/Public/ProposalHeading.vue';
import SlotPicker, { type Slot } from '@/Components/Public/SlotPicker.vue';
import Button from '@/Components/ui/Button.vue';
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import axios from 'axios';
import { computed, nextTick, reactive, ref } from 'vue';

/**
 * The public booking page. **No calendar.**
 *
 * A calendar makes a customer assemble an appointment out of two independent
 * choices and hold the constraint in their head while they do it: "90 minutes,
 * with Ana, some morning, and it has to be after the school run." This page
 * states a finished appointment — decided by `AppointmentSuggester`, with the
 * phrase that justifies it — and asks them to accept it. Nine visible elements,
 * one column, no card, no wizard, no step counter.
 *
 * The pieces, in order down the page:
 *
 *   1. the salon mark and name (in the Blade shell, not here)
 *   2. the context line, which leads with the *reason* for this appointment
 *   3. the appointment, at 34px, the largest thing on screen by a wide margin
 *   4. the cost, as one line
 *   5. the primary action, whose label names the outcome
 *   6. the refund line, as a date she can act on
 *   7. `Or`, on a hairline
 *   8. three alternatives, each a complete appointment
 *   9. `Pick another day` — the quietest thing here
 *
 * Details are asked for **after** the appointment is accepted, revealed inline
 * beneath a proposal that stays on screen. That is disclosure, not a step: a
 * new customer is never asked to fill in a form for an appointment they have
 * not yet agreed to, and nothing has to be re-entered when they change their
 * mind about the time.
 *
 * A returning customer — recognised by the manage-link cookie or a reminder
 * link, never by a typed-in email — skips the form entirely. One tap books.
 */

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
    };
    vertical: { subject_singular: string; subject_fields: SubjectField[]; appointment_singular: string };
    today: string;
    urls: { page: string; availability: string; store: string; waitlist: string };
}>();

/*
 * One flat state, deliberately not a step machine. `proposal` is always the
 * appointment currently on offer; everything else is a panel that is either
 * revealed or not. There is no state in which the proposal is off screen, which
 * is what stops this becoming the wizard it replaced.
 */
const proposal = ref<ProposalPayload | null>(props.suggestion.primary);
const alternatives = ref<ProposalPayload[]>(props.suggestion.alternatives);
const context = ref(props.suggestion.context ?? '');

const pickerOpen = ref(false);
const detailsOpen = ref(false);
const waitlistSaved = ref(false);

const error = ref('');
const submitting = ref(false);
const booked = ref(false);

// The inline picker's own state.
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

// Stripe, only if a deposit is actually taken.
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

/** Monday-first, so the rail reads the way a British week does. */
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

const shiftWeek = async (direction: number) => {
    weekStart.value = shiftDays(week.value[0], direction * 7);
    await loadDays();
};

const pickDay = (iso: string) => {
    weekStart.value = iso;
    // Selecting a day alone changes nothing about the proposal — a day is half
    // an appointment, and half an appointment is what this page exists to avoid.
};

/**
 * Picking a time rewrites the proposal and collapses the picker.
 *
 * The reason line becomes "You chose this time" rather than keeping whatever
 * `AppointmentSuggester` said about a slot the customer has now overruled.
 * Leaving "your usual Tuesday" above a Thursday she picked herself would be the
 * page lying about its own reasoning.
 */
const pickSlot = (slot: Slot) => {
    const base = proposal.value;
    if (!base) return;

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
        action_label: `Reserve ${local.toLocaleDateString(undefined, { weekday: 'long' })} at ${slot.starts_at_local}`,
        // The refund cut-off moves with the appointment and this page cannot
        // recompute the salon's own window, so it is dropped rather than shown
        // as a date that is no longer true.
        free_until: null,
    };

    context.value = ['You chose this time', ...(props.suggestion.context ?? '').split(' · ').slice(1)].join(' · ');
    pickerOpen.value = false;
};

/**
 * A different service is a different appointment, so the server re-proposes it.
 *
 * Everything about the proposal — the duration, the staff who can do it, the
 * price, the deposit and the reason — is the suggester's answer for one
 * service. Rewriting it in the browser would be re-implementing the ranking in
 * TypeScript, badly, and it would be the second copy of it.
 */
const switchService = (id: number) => {
    window.location.href = `${props.urls.page}?service=${id}`;
};

const acceptAlternative = (alternative: ProposalPayload) => {
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

/**
 * The one forward action.
 *
 * A returning customer books straight away. A new one is asked for their
 * details first, in fields that appear under the proposal they have just
 * accepted — and the button keeps the same label, because it is still the same
 * outcome.
 */
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

        if (data.booking.status !== 'confirmed') {
            // Pending with no client secret means there is no way to pay, and
            // the hold will quietly expire. Never imply the slot is held.
            error.value = 'We couldn’t set up payment. Nothing has been charged — please try again in a moment.';

            return;
        }

        booked.value = true;
    } catch (err: unknown) {
        const status = axios.isAxiosError(err) ? err.response?.status : 0;
        const message = axios.isAxiosError(err) ? err.response?.data?.message : null;

        if (status === 409) {
            error.value = 'That time was just taken. Here is what is still free.';
            await openPicker();
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

// ---- Stripe ------------------------------------------------------------
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

    window.location.href = manageUrl.value;
};

const joinWaitlist = async () => {
    error.value = '';

    try {
        await axios.post(props.urls.waitlist, {
            service_id: serviceId.value,
            name: details.name || props.suggestion.customer_name || 'Waiting',
            email: details.email,
            phone: details.phone,
            preferred_days: [],
            preferred_times: 'any',
        });
        waitlistSaved.value = true;
    } catch {
        error.value = 'We couldn’t add you to the waitlist. Please try again.';
    }
};
</script>

<template>
    <div>
        <p v-if="error" class="mb-4 text-15 text-danger" role="alert">{{ error }}</p>

        <!-- ============================================================
             Paid. The deposit is the last thing between here and booked.
             ============================================================ -->
        <section v-if="clientSecret" class="space-y-4">
            <h1 class="text-20 font-medium">Pay the deposit</h1>
            <p class="text-15 text-ink-2">
                {{ proposal?.cost_line }}. The appointment is held for 15 minutes while you pay.
            </p>
            <div id="card-element" class="rounded border border-rule bg-white p-3"></div>
            <Button variant="brand" block :loading="paying" @click="confirmPay">Pay now</Button>
        </section>

        <!-- ============================================================
             Booked, with nothing to pay.
             ============================================================ -->
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

        <!-- ============================================================
             Nothing bookable at all. The one screen where the waitlist is
             the primary action rather than a footnote.
             ============================================================ -->
        <section v-else-if="!proposal" class="space-y-4">
            <h1 class="text-24 font-medium">{{ tenant.name }} is fully booked</h1>
            <p class="text-15 text-ink-2">
                There is nothing free in the diary at the moment. Leave your number and we will text
                you the moment something opens up — which happens more often than you would think.
            </p>

            <p v-if="waitlistSaved" class="text-15">
                Done. We’ll text you as soon as a slot opens.
            </p>
            <div v-else class="space-y-3">
                <TextInput v-model="details.name" label="Your name" autocomplete="name" />
                <TextInput v-model="details.email" label="Email" type="email" autocomplete="email" />
                <TextInput v-model="details.phone" label="Mobile" type="tel" autocomplete="tel" />
                <Button variant="brand" block @click="joinWaitlist">Text me when something opens</Button>
            </div>
        </section>

        <!-- ============================================================
             The proposal. Everything above is an outcome; this is the page.
             ============================================================ -->
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

                <!--
                    Changing the service lives in here rather than beside the
                    proposal. The proposal view is nine elements and a tenth
                    would be a tenth; a customer who has already opened the
                    picker is a customer who is browsing, and this is where
                    browsing belongs.
                -->
                <template v-if="services.length > 1">
                    <h3 class="caption mt-8 border-b border-b-rule pb-2">Something else</h3>
                    <ul class="mt-2">
                        <li v-for="service in services" :key="service.id">
                            <ChoiceRow
                                :label="service.name"
                                :meta="`${service.duration_minutes} min · ${service.price.formatted}`"
                                @pick="switchService(service.id)"
                            />
                        </li>
                    </ul>
                </template>

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

                <!-- The refund window as a date, not as arithmetic. -->
                <p v-if="proposal.free_until" class="mt-3 text-center text-13 text-ink-2">
                    Free to cancel or move until {{ proposal.free_until }}
                </p>
                <p v-else-if="tenant.takes_deposits && proposal.deposit.amount > 0" class="mt-3 text-center text-13 text-ink-2">
                    This is inside the cancellation window, so the deposit is not refundable
                </p>

                <!-- ---- the details, revealed under the proposal ---- -->
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
                        :label="`${vertical.subject_singular} name`"
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

                <!-- ---- Or, on a hairline ---- -->
                <div v-if="alternatives.length" class="mt-8 flex items-center gap-3" aria-hidden="true">
                    <span class="block flex-1 border-t border-t-rule"></span>
                    <span class="text-13 text-ink-2">Or</span>
                    <span class="block flex-1 border-t border-t-rule"></span>
                </div>

                <template v-if="alternatives.length">
                    <h2 class="sr-only">Other times</h2>
                    <ul class="mt-2">
                        <li v-for="alternative in alternatives" :key="alternative.starts_at">
                            <!--
                                No aria-label. The visible text is the
                                accessible name, which is what WCAG 2.5.3
                                (Label in Name) asks for: a speech-input user
                                saying "Wednesday morning" activates the row
                                they can see. An aria-label that reworded it
                                into "Wednesday 2 September at 09:15" read
                                better and matched nothing.
                            -->
                            <ChoiceRow
                                :label="alternative.reason"
                                :meta="alternative.meta"
                                @pick="acceptAlternative(alternative)"
                            />
                        </li>
                    </ul>
                </template>

                <!-- ---- the quietest thing on the page ---- -->
                <p class="mt-6 text-center">
                    <QuietAction @click="openPicker">Pick another day</QuietAction>
                </p>
            </template>
        </template>
    </div>
</template>
