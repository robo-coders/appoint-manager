<script setup lang="ts">
import axios from 'axios';
import { computed, nextTick, reactive, ref, watch } from 'vue';

interface Money {
    amount: number;
    formatted: string;
    currency: string;
}

interface ServiceOption {
    id: number;
    name: string;
    duration_minutes: number;
    price: Money;
    deposit_amount: Money;
}

interface SubjectField {
    key: string;
    label: string;
    type: string;
    required?: boolean;
    options?: string[];
}

interface Slot {
    starts_at: string;
    starts_at_local: string;
    staff_ids: number[];
}

const props = defineProps<{
    tenant: {
        name: string;
        slug: string;
        timezone: string;
        currency: string;
        takes_deposits: boolean;
        city?: string | null;
        postcode?: string | null;
    };
    stripePublishableKey?: string | null;
    services: ServiceOption[];
    vertical: {
        subject_singular: string;
        subject_fields: SubjectField[];
        appointment_singular: string;
    };
    today: string;
    urls: { availability: string; store: string; waitlist: string };
}>();

const step = ref<'service' | 'date' | 'time' | 'details' | 'confirm' | 'pay' | 'done'>('service');
const serviceId = ref<number | null>(null);
const date = ref<string | null>(null);
const slot = ref<Slot | null>(null);
const days = ref<Record<string, Slot[]>>({});
const loadingDays = ref(false);
const submitting = ref(false);
const error = ref('');
const selectedSubjectId = ref<number | null>(null);
const paymentState = ref('');
const waitlistOpen = ref(false);
const waitlistSaved = ref(false);
const clientSecret = ref('');
const stripeAccount = ref('');
const manageUrl = ref('');
const paying = ref(false);

const loadStripeJs = async () => {
    if ((window as unknown as { Stripe?: unknown }).Stripe) {
        return;
    }

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
    if (!StripeCtor || !props.stripePublishableKey) {
        return;
    }
    const stripe = StripeCtor(props.stripePublishableKey, { stripeAccount: stripeAccount.value || undefined });
    const elements = stripe.elements();
    const card = elements.create('card');
    card.mount('#card-element');
    (window as unknown as { __kestrelCard: unknown; __kestrelStripe: unknown }).__kestrelCard = card;
    (window as unknown as { __kestrelStripe: unknown }).__kestrelStripe = stripe;
};

const confirmPay = async () => {
    paying.value = true;
    error.value = '';
    const stripe = (window as unknown as { __kestrelStripe?: { confirmCardPayment: Function } }).__kestrelStripe;
    const card = (window as unknown as { __kestrelCard?: unknown }).__kestrelCard;
    if (!stripe || !card) {
        error.value = 'Payment form is not ready.';
        paying.value = false;
        return;
    }
    const result = await stripe.confirmCardPayment(clientSecret.value, {
        payment_method: { card },
    });
    if (result.error) {
        error.value = result.error.message ?? 'Payment failed.';
        paying.value = false;
        return;
    }
    window.location.href = manageUrl.value;
};

const details = reactive({
    name: '',
    email: '',
    phone: '',
    subject_name: '',
    subject_attributes: Object.fromEntries(props.vertical.subject_fields.map((field) => [field.key, ''])) as Record<string, string>,
});

const selectedService = computed(() => props.services.find((service) => service.id === serviceId.value) ?? null);

const remainder = computed(() => {
    if (!selectedService.value) {
        return '';
    }

    const left = selectedService.value.price.amount - selectedService.value.deposit_amount.amount;

    return new Intl.NumberFormat(undefined, {
        style: 'currency',
        currency: props.tenant.currency,
    }).format(left / 100);
});

const depositLine = computed(() => {
    if (!selectedService.value) {
        return '';
    }

    if (!props.tenant.takes_deposits || selectedService.value.deposit_amount.amount === 0) {
        return `${selectedService.value.price.formatted} on the day`;
    }

    return `${selectedService.value.deposit_amount.formatted} now, ${remainder.value} on the day`;
});

const calendarDay = (iso: string, offset: number): string => {
    const [year, month, day] = iso.split('-').map(Number);
    const next = new Date(year, month - 1, day + offset);

    return [
        next.getFullYear(),
        String(next.getMonth() + 1).padStart(2, '0'),
        String(next.getDate()).padStart(2, '0'),
    ].join('-');
};

const dateOptions = computed(() => Array.from({ length: 14 }, (_, index) => calendarDay(props.today, index)));

const loadAvailability = async () => {
    if (!serviceId.value) {
        return;
    }

    loadingDays.value = true;
    error.value = '';

    try {
        const from = dateOptions.value[0];
        const to = dateOptions.value[dateOptions.value.length - 1];
        const { data } = await axios.get(props.urls.availability, {
            params: { service: serviceId.value, from, to },
        });
        days.value = data.days ?? {};
    } catch {
        error.value = 'Times didn’t load. Check your connection and try again.';
    } finally {
        loadingDays.value = false;
    }
};

const pickService = (id: number) => {
    serviceId.value = id;
    date.value = null;
    slot.value = null;
    step.value = 'date';
    loadAvailability();
};

const pickDate = (value: string) => {
    if ((days.value[value] ?? []).length === 0) {
        return;
    }

    date.value = value;
    slot.value = null;
    step.value = 'time';
};

const pickTime = (value: Slot) => {
    slot.value = value;
    step.value = 'details';
};

const goConfirm = () => {
    error.value = '';

    if (!details.name || !details.email || !details.phone) {
        error.value = 'Please fill in your details.';
        return;
    }

    if (!selectedSubjectId.value) {
        for (const field of props.vertical.subject_fields) {
            if (field.required && !details.subject_attributes[field.key]) {
                error.value = `Please add ${field.label.toLowerCase()}.`;
                return;
            }
        }

        if (!details.subject_name) {
            error.value = 'Please add a name.';
            return;
        }
    }

    step.value = 'confirm';
};

const submit = async () => {
    if (!selectedService.value || !slot.value) {
        return;
    }

    submitting.value = true;
    error.value = '';

    try {
        const { data } = await axios.post(props.urls.store, {
            service_id: selectedService.value.id,
            starts_at: slot.value.starts_at,
            staff_id: slot.value.staff_ids[0] ?? null,
            name: details.name,
            email: details.email,
            phone: details.phone,
            subject_id: selectedSubjectId.value,
            subject_name: selectedSubjectId.value ? null : details.subject_name,
            subject_attributes: selectedSubjectId.value ? {} : details.subject_attributes,
        });
        if (data.payment?.client_secret) {
            paymentState.value = 'pay';
            clientSecret.value = data.payment.client_secret;
            stripeAccount.value = data.payment.connected_account;
            manageUrl.value = data.booking.manage_url;
            step.value = 'pay';
            await nextTick();
            mountCard();
            return;
        }
        if (data.booking.status !== 'confirmed') {
            // Pending with no client secret means there is no way to pay. Never show
            // "we're holding the slot" for a hold that will silently expire.
            error.value = 'We couldn’t set up payment. Nothing has been charged — please try again in a moment.';
            step.value = 'time';
            loadAvailability();
            return;
        }

        paymentState.value = 'confirmed';
        step.value = 'done';
    } catch (err: unknown) {
        const status = axios.isAxiosError(err) ? err.response?.status : 0;
        const message = axios.isAxiosError(err) ? err.response?.data?.message : null;

        if (status === 409) {
            error.value = 'That time was just taken — here’s what’s still free.';
            step.value = 'time';
            loadAvailability();
        } else if (status === 503) {
            // Payments were unreachable. The slot has been released and no card was
            // charged, so send them back to pick a time rather than leaving them on a
            // screen that implies the booking is being held.
            error.value = message ?? 'We couldn’t reach payments. Nothing has been charged — please try again in a moment.';
            step.value = 'time';
            loadAvailability();
        } else if (status === 422 && message) {
            error.value = message;
        } else {
            error.value = 'We couldn’t finish this booking. Check your details and try again.';
        }
    } finally {
        submitting.value = false;
    }
};

const formatDay = (value: string) =>
    new Date(`${value}T00:00:00`).toLocaleDateString(undefined, {
        weekday: 'short',
        day: 'numeric',
        month: 'short',
    });
</script>

<template>
    <div class="space-y-6">
        <p v-if="error" class="text-14 text-danger" role="alert">{{ error }}</p>

        <section v-if="step === 'service'" class="space-y-3">
            <h1 class="font-display text-20 tracking-20">Choose a service</h1>
            <button
                v-for="service in services"
                :key="service.id"
                type="button"
                class="min-h-tap w-full rounded border border-rule bg-white p-4 text-left active:translate-y-px"
                @click="pickService(service.id)"
            >
                <p class="font-medium">{{ service.name }}</p>
                <p class="mt-1 text-14 text-ink-2">
                    {{ service.duration_minutes }} min · {{ service.price.formatted }}
                </p>
            </button>
        </section>

        <section v-else-if="step === 'date'" class="space-y-3">
            <button type="button" class="min-h-tap text-14 text-ink-2" @click="step = 'service'">Back</button>
            <h1 class="font-display text-20 tracking-20">Pick a date</h1>
            <div v-if="loadingDays" class="grid grid-cols-2 gap-2" aria-label="Loading">
                <div v-for="n in 6" :key="n" class="h-11 rounded bg-paper-sunk" />
            </div>
            <div v-else class="grid grid-cols-2 gap-2">
                <button
                    v-for="day in dateOptions"
                    :key="day"
                    type="button"
                    class="min-h-tap rounded border px-3 text-left text-14"
                    :class="
                        (days[day] ?? []).length
                            ? 'border-rule bg-white text-ink active:translate-y-px'
                            : 'cursor-not-allowed border-rule bg-paper text-ink-2 opacity-40'
                    "
                    :disabled="!(days[day] ?? []).length"
                    @click="pickDate(day)"
                >
                    {{ formatDay(day) }}
                    <span class="mt-0.5 block text-12 text-ink-2">
                        {{ (days[day] ?? []).length ? 'Times available' : 'No times' }}
                    </span>
                </button>
            </div>
        </section>

        <section v-else-if="step === 'time'" class="space-y-3">
            <button type="button" class="min-h-tap text-14 text-ink-2" @click="step = 'date'">Back</button>
            <h1 class="font-display text-20 tracking-20">Pick a time</h1>
            <div class="grid grid-cols-3 gap-2">
                <button
                    v-for="option in days[date ?? ''] ?? []"
                    :key="option.starts_at"
                    type="button"
                    class="min-h-tap rounded border border-rule bg-white text-14 active:translate-y-px"
                    @click="pickTime(option)"
                >
                    {{ option.starts_at_local }}
                </button>
            </div>
            <p v-if="date && !(days[date] ?? []).length" class="text-14 text-ink-2">No times left this day.</p>
            <button
                v-if="date && !(days[date] ?? []).length"
                type="button"
                class="min-h-tap text-14 underline decoration-rule underline-offset-4"
                @click="waitlistOpen = true"
            >
                Tell me if something comes up
            </button>
        </section>

        <section v-else-if="step === 'details'" class="space-y-4">
            <button type="button" class="min-h-tap text-14 text-ink-2" @click="step = 'time'">Back</button>
            <h1 class="font-display text-20 tracking-20">Your details</h1>
            <label class="block text-13">
                Your name
                <input v-model="details.name" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3" />
            </label>
            <label class="block text-13">
                Email
                <input v-model="details.email" type="email" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3" />
            </label>
            <label class="block text-13">
                Phone
                <input v-model="details.phone" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3" />
            </label>

            <div class="space-y-3">
                <label class="block text-13">
                    {{ vertical.subject_singular }} name
                    <input v-model="details.subject_name" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3" />
                </label>
                <label v-for="field in vertical.subject_fields" :key="field.key" class="block text-13">
                    {{ field.label }}
                    <select
                        v-if="field.type === 'select'"
                        v-model="details.subject_attributes[field.key]"
                        class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3"
                    >
                        <option value="">Select</option>
                        <option v-for="option in field.options ?? []" :key="option" :value="option">{{ option }}</option>
                    </select>
                    <textarea
                        v-else-if="field.type === 'textarea'"
                        v-model="details.subject_attributes[field.key]"
                        class="mt-1 w-full rounded border border-rule bg-white px-3 py-3"
                    />
                    <input
                        v-else
                        v-model="details.subject_attributes[field.key]"
                        class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3"
                    />
                </label>
            </div>

            <button
                type="button"
                class="min-h-tap w-full rounded bg-ink text-14 font-medium text-white active:translate-y-px"
                @click="goConfirm"
            >
                Continue
            </button>
        </section>

        <section v-else-if="step === 'confirm' && selectedService && slot" class="space-y-4">
            <button type="button" class="min-h-tap text-14 text-ink-2" @click="step = 'details'">Back</button>
            <h1 class="font-display text-20 tracking-20">Confirm</h1>
            <div class="rounded border border-rule bg-white p-4 text-14">
                <p>{{ selectedService.name }}</p>
                <p class="mt-1 text-ink-2">{{ date }} at {{ slot.starts_at_local }}</p>
                <p class="mt-3 font-medium">{{ depositLine }}</p>
            </div>
            <button
                type="button"
                class="min-h-tap w-full rounded bg-ink text-14 font-medium text-white disabled:opacity-50 active:translate-y-px"
                :disabled="submitting"
                @click="submit"
            >
                Confirm booking
            </button>
        </section>

        <section v-else-if="step === 'pay'" class="space-y-4">
            <h1 class="font-display text-20 tracking-20">Pay the deposit</h1>
            <p class="text-14 text-ink-2">{{ depositLine }}. We hold the slot for 15 minutes.</p>
            <div id="card-element" class="rounded border border-rule bg-white p-3"></div>
            <p v-if="error" class="text-14 text-danger">{{ error }}</p>
            <button
                type="button"
                class="min-h-tap w-full rounded bg-ink text-14 font-medium text-white disabled:opacity-50 active:translate-y-px"
                :disabled="paying"
                @click="confirmPay"
            >
                Pay now
            </button>
        </section>

        <section v-else-if="step === 'done'" class="space-y-3 rounded border border-rule bg-white p-6">
            <h1 class="font-display text-20 tracking-20">You’re booked</h1>
            <p class="text-14 text-ink-2">We’ll send a confirmation shortly.</p>
        </section>

        <section v-if="waitlistOpen" class="space-y-3 rounded border border-rule bg-white p-4">
            <h2 class="text-17 font-medium">Waitlist</h2>
            <p v-if="waitlistSaved" class="text-14">We’ll text you if a slot opens.</p>
            <form
                v-else
                class="space-y-3"
                @submit.prevent="
                    axios
                        .post(urls.waitlist, {
                            service_id: serviceId,
                            name: details.name || 'Waiting',
                            email: details.email,
                            phone: details.phone,
                            preferred_days: date ? [new Date(date + 'T00:00:00').getDay() || 7] : [],
                            preferred_times: 'any',
                        })
                        .then(() => (waitlistSaved = true))
                "
            >
                <label class="block text-13">
                    Your name
                    <input v-model="details.name" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3" />
                </label>
                <label class="block text-13">
                    Email
                    <input v-model="details.email" type="email" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3" />
                </label>
                <label class="block text-13">
                    Phone
                    <input v-model="details.phone" class="mt-1 min-h-tap w-full rounded border border-rule bg-white px-3" />
                </label>
                <button type="submit" class="min-h-tap w-full rounded bg-ink text-14 font-medium text-white">
                    Join the waitlist
                </button>
            </form>
        </section>
    </div>
</template>
