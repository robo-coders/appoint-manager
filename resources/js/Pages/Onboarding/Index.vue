<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import BookingModeFields from '@/Components/BookingModeFields.vue';
import Callout from '@/Components/ui/Callout.vue';
import Combobox from '@/Components/ui/Combobox.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import RadioGroup from '@/Components/ui/RadioGroup.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import ToastContainer from '@/Components/ui/ToastContainer.vue';
import Toggle from '@/Components/ui/Toggle.vue';
import { toast } from '@/lib/toast';
import { penceToPoundsInput, poundsInputToPence } from '@/lib/money';
import { weekdays } from '@/lib/weekdays';
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

type Hour = { weekday: number; open: boolean; start_time: string; end_time: string };

const props = defineProps<{
    step: string;
    completedSteps: string[];
    steps: { key: string; label: string }[];
    onboardingSteps: string[];
    timezones: string[];
    basics: { name: string; slug: string; type: string; hours: Hour[] };
    verticals: { value: string; label: string; note: string }[];
    business: {
        timezone: string;
        phone: string | null;
        address_line_1: string | null;
        address_line_2: string | null;
        city: string | null;
        postcode: string | null;
        booking_mode: 'automated' | 'request';
        request_requires_deposit: boolean;
    };
    service: {
        id: number | null;
        name: string;
        duration_minutes: number;
        price: number;
        deposit_amount: number;
    };
    staff: { id: number; name: string; email: string; is_owner: boolean }[];
    bookingUrl: string;
    firstBookingDefault: string;
}>();

const page = usePage();
const errors = computed(() => (page.props.errors ?? {}) as Record<string, string>);

const step = ref(props.step);
const saving = ref(false);

const transportError = ref('');
const retry = ref<(() => void) | null>(null);

const form = ref({
    name: props.basics.name,
    slug: props.basics.slug,
    type: props.basics.type,
    hours: props.basics.hours.map((hour) => ({ ...hour })),

    timezone: props.business.timezone,
    phone: props.business.phone ?? '',
    address_line_1: props.business.address_line_1 ?? '',
    address_line_2: props.business.address_line_2 ?? '',
    city: props.business.city ?? '',
    postcode: props.business.postcode ?? '',
    booking_mode: props.business.booking_mode,
    request_requires_deposit: props.business.request_requires_deposit,

    service: {
        id: props.service.id,
        name: props.service.name,
        duration_minutes: String(props.service.duration_minutes),
        price: penceToPoundsInput(props.service.price),
        deposit_amount: penceToPoundsInput(props.service.deposit_amount),
    },

    staffName: '',
    staffEmail: '',
    staffCanSeeContacts: true,
});

const index = computed(() => Math.max(0, props.onboardingSteps.indexOf(step.value)));
const total = computed(() => props.onboardingSteps.length);
const label = computed(() => props.steps.find((s) => s.key === step.value)?.label ?? '');
const isFirst = computed(() => index.value === 0);
const isLast = computed(() => index.value === total.value - 1);

const nextLabels: Record<string, string> = {
    basics: 'Continue to details',
    business: 'Continue to services',
    services: 'Continue to staff',
    staff: 'Continue to your link',
    link: 'Go to my diary',
};

const nextLabel = computed(() => nextLabels[step.value] ?? 'Continue');

const goBack = () => {
    transportError.value = '';
    step.value = props.onboardingSteps[Math.max(0, index.value - 1)];
};

const submit = (method: 'patch' | 'post', url: string, data: Record<string, FormDataConvertible>) => {
    const send = () => {
        saving.value = true;
        transportError.value = '';

        router[method](url, data, {
            preserveScroll: true,
            preserveState: true,
            onSuccess: (page) => {
                retry.value = null;
                step.value = (page.props as unknown as { step: string }).step;
            },
            onFinish: () => {
                saving.value = false;
            },
        });
    };

    retry.value = send;
    send();
};

watch(
    () => props.step,
    (value) => {
        step.value = value;
    },
);

onMounted(() => {
    const stopException = router.on('exception', () => {
        saving.value = false;
        transportError.value = 'That did not save — the connection dropped. Nothing you typed has been lost.';
    });

    const stopInvalid = router.on('invalid', (event) => {
        event.preventDefault();
        saving.value = false;
        transportError.value = 'That did not save. Something went wrong at our end — try again.';
    });

    onUnmounted(() => {
        stopException();
        stopInvalid();
    });
});

const slugState = ref<'idle' | 'checking' | 'free' | 'taken'>('idle');
const slugSuggestion = ref<string | null>(null);
const slugEdited = ref(false);

const slugify = (value: string) =>
    value
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[^\w\s-]/g, '')
        .trim()
        .replace(/[\s_]+/g, '-')
        .replace(/-+/g, '-')
        .replace(/^-|-$/g, '');

watch(
    () => form.value.name,
    (value) => {
        if (!slugEdited.value) {
            form.value.slug = slugify(value);
        }
    },
);

let slugTimer: ReturnType<typeof setTimeout> | undefined;

watch(
    () => form.value.slug,
    (value) => {
        clearTimeout(slugTimer);
        slugSuggestion.value = null;

        if (value === props.basics.slug || value.length < 3) {
            slugState.value = 'idle';

            return;
        }

        slugState.value = 'checking';

        slugTimer = setTimeout(async () => {
            try {
                const response = await fetch(`${route('onboarding.slug')}?slug=${encodeURIComponent(value)}`, {
                    headers: { Accept: 'application/json' },
                });

                if (!response.ok) {
                    slugState.value = 'idle';

                    return;
                }

                const body = await response.json();

                if (body.slug !== slugify(form.value.slug)) {
                    return;
                }

                slugState.value = body.available ? 'free' : 'taken';
                slugSuggestion.value = body.suggestion;
            } catch {
                slugState.value = 'idle';
            }
        }, 350);
    },
);

onUnmounted(() => clearTimeout(slugTimer));

const verticalOptions = computed(() =>
    props.verticals.map((vertical) => ({
        value: vertical.value,
        label: vertical.label,
        hint: vertical.note,
    })),
);

const dayName = (weekday: number) => weekdays.find((day) => day.value === weekday)?.label ?? '';

const openDays = computed(() => form.value.hours.filter((hour) => hour.open).length);

const toggleDay = (hour: Hour) => {
    hour.open = !hour.open;
};

const submitBasics = () =>
    submit('patch', route('onboarding.basics'), {
        name: form.value.name,
        slug: form.value.slug,
        type: form.value.type,
        hours: form.value.hours,
    });

const submitBusiness = () =>
    submit('patch', route('onboarding.business'), {
        timezone: form.value.timezone,
        phone: form.value.phone,
        address_line_1: form.value.address_line_1,
        address_line_2: form.value.address_line_2,
        city: form.value.city,
        postcode: form.value.postcode,
        booking_mode: form.value.booking_mode,
        request_requires_deposit: form.value.request_requires_deposit,
    });

const priceInPence = computed(() => poundsInputToPence(form.value.service.price));
const depositInPence = computed(() => poundsInputToPence(form.value.service.deposit_amount));
const durationInMinutes = computed(() => parseInt(form.value.service.duration_minutes, 10) || 0);

const touched = ref<Record<string, boolean>>({});
const touch = (field: string) => {
    touched.value[field] = true;
};

const localErrors = computed(() => {
    const found: Record<string, string> = {};

    if (touched.value.duration && durationInMinutes.value < 5) {
        found.duration = 'Five minutes is the shortest appointment.';
    } else if (touched.value.duration && durationInMinutes.value % 5 !== 0) {
        found.duration = 'Use a length in five minute steps.';
    }

    if (touched.value.price && !/^\d+(\.\d{1,2})?$/.test(form.value.service.price.trim())) {
        found.price = 'Give a price, like 42 or 42.00. Use 0 if it is free.';
    }

    if (touched.value.deposit && !/^\d+(\.\d{1,2})?$/.test(form.value.service.deposit_amount.trim())) {
        found.deposit = 'Give an amount, like 10 or 10.00. Use 0 for no deposit.';
    } else if (touched.value.deposit && depositInPence.value > priceInPence.value) {
        found.deposit = 'The deposit cannot be more than the price.';
    }

    return found;
});

const submitService = () =>
    submit('patch', route('onboarding.services'), {
        id: form.value.service.id,
        name: form.value.service.name,
        duration_minutes: durationInMinutes.value,
        price: priceInPence.value,
        deposit_amount: depositInPence.value,
    });

const hasStaffEntry = computed(
    () => form.value.staffName.trim() !== '' || form.value.staffEmail.trim() !== '',
);

const submitStaff = (skip = false) =>
    submit('patch', route('onboarding.staff'), {
        staff:
            skip || !hasStaffEntry.value
                ? null
                : {
                      name: form.value.staffName.trim(),
                      email: form.value.staffEmail.trim().toLowerCase(),
                      can_see_customer_contacts: form.value.staffCanSeeContacts,
                  },
    });

const skip = () => submitStaff(true);

const liveBookingUrl = computed(() =>
    props.bookingUrl.replace(/\/[^/]*$/, `/${form.value.slug || props.basics.slug}`),
);

const displayUrl = computed(() => liveBookingUrl.value.replace(/^https?:\/\//, ''));

const copyState = ref<'idle' | 'manual'>('idle');
const urlEl = ref<HTMLElement | null>(null);

const copy = async () => {
    try {
        if (!navigator.clipboard?.writeText) {
            throw new Error('unavailable');
        }

        await navigator.clipboard.writeText(liveBookingUrl.value);
        copyState.value = 'idle';
        toast.success('Link copied');
    } catch {
        copyState.value = 'manual';
        selectUrl();
    }
};

const selectUrl = () => {
    const node = urlEl.value;

    if (!node) {
        return;
    }

    const range = document.createRange();
    range.selectNodeContents(node);
    const selection = window.getSelection();
    selection?.removeAllRanges();
    selection?.addRange(range);
};

const copyLabel = computed(() => (copyState.value === 'manual' ? 'Selected' : 'Copy'));

const qr = ref('');

const drawQr = async () => {
    const styles = getComputedStyle(document.documentElement);

    try {
        qr.value = await QRCode.toString(liveBookingUrl.value, {
            type: 'svg',
            margin: 1,
            errorCorrectionLevel: 'M',
            color: {
                dark: styles.getPropertyValue('--ink').trim() || undefined,
                light: styles.getPropertyValue('--paper').trim() || undefined,
            },
        });
    } catch {
        qr.value = '';
    }
};

watch([() => step.value, liveBookingUrl], () => {
    if (step.value === 'link') {
        drawQr();
    }
});

onMounted(() => {
    if (step.value === 'link') {
        drawQr();
    }
});

const finish = () =>
    submit('post', route('onboarding.complete'), {
        slug: form.value.slug,
        first_booking: null,
    });

watch(
    () => errors.value.slug,
    (message) => {
        if (message && step.value === 'link') {
            step.value = 'basics';
            slugEdited.value = true;
        }
    },
);

const onNext = () => {
    if (saving.value) {
        return;
    }

    const handlers: Record<string, () => void> = {
        basics: submitBasics,
        business: submitBusiness,
        services: submitService,
        staff: () => submitStaff(),
        link: finish,
    };

    handlers[step.value]?.();
};
</script>

<template>
    <div class="flex min-h-screen flex-col bg-paper">
        <Head :title="`Set up · ${label}`" />
        <ToastContainer />

        <div class="flex gap-1" role="presentation">
            <div
                v-for="(key, at) in onboardingSteps"
                :key="key"
                class="h-1 flex-1 transition duration-fast ease-product"
                :class="at <= index ? 'bg-accent' : 'bg-rule'"
            />
        </div>

        <div class="flex items-center justify-between gap-6 px-6 pt-6 md:px-12">
            <p class="eyebrow">DiaryDesk setup · {{ label }}</p>
            <QuietAction v-if="step === 'staff'" :disabled="saving" @click="skip">Skip for now</QuietAction>
        </div>

        <div class="mx-auto w-full max-w-3xl flex-1 px-6 pb-16 pt-12 md:px-12">
            <Callout v-if="transportError" tone="danger" title="Not saved" class="mb-8">
                {{ transportError }}
                <template #action>
                    <Button variant="secondary" :disabled="saving" @click="retry?.()">Try again</Button>
                </template>
            </Callout>

            <section v-if="step === 'basics'">
                <h1 class="text-34 tracking-34">Tell us about the business</h1>
                <p class="mt-2 max-w-measure text-14 text-ink-2">
                    This sets your booking page name and the default working week. You can change both later in
                    Settings.
                </p>

                <div class="mt-12 max-w-measure">
                    <TextInput
                        v-model="form.name"
                        label="Business name"
                        :error="errors.name"
                        autocomplete="organization"
                        required
                        autofocus
                    />
                </div>

                <div class="mt-4 max-w-measure">
                    <TextInput
                        v-model="form.slug"
                        label="Booking page address"
                        :error="errors.slug"
                        mono
                        required
                        @input="slugEdited = true"
                    />
                    <p class="mt-2 font-mono text-12 text-ink-2">{{ displayUrl }}</p>

                    <p v-if="slugState === 'checking'" class="mt-1 text-12 text-ink-3">Checking…</p>
                    <p v-else-if="slugState === 'free'" class="mt-1 text-12 text-ink-2">That address is free.</p>
                    <p v-else-if="slugState === 'taken'" class="mt-1 text-12 text-danger" role="alert">
                        Already taken.
                        <QuietAction
                            v-if="slugSuggestion"
                            tone="ink"
                            @click="
                                form.slug = slugSuggestion;
                                slugEdited = true;
                            "
                        >
                            Use {{ slugSuggestion }}
                        </QuietAction>
                    </p>
                </div>

                <div class="mt-12 max-w-measure">
                    <RadioGroup
                        v-model="form.type"
                        legend="What kind of appointments do you take?"
                        :options="verticalOptions"
                        :error="errors.type"
                    />
                </div>

                <fieldset class="mt-12">
                    <legend class="text-13 font-medium text-ink">Opening hours</legend>
                    <FieldError :message="errors.hours" />

                    <div class="mt-3 rule-line">
                        <div
                            v-for="(hour, at) in form.hours"
                            :key="hour.weekday"
                            class="flex flex-wrap items-center gap-3 border-b border-rule py-3"
                        >
                            <span class="w-16 shrink-0 text-13" :class="hour.open ? 'text-ink' : 'text-ink-3'">
                                {{ dayName(hour.weekday) }}
                            </span>

                            <div v-if="hour.open" class="flex flex-1 flex-wrap items-center gap-2">
                                <TextInput
                                    v-model="hour.start_time"
                                    :label="`${dayName(hour.weekday)} opens`"
                                    label-hidden
                                    type="time"
                                />
                                <span class="text-13 text-ink-3">to</span>
                                <TextInput
                                    v-model="hour.end_time"
                                    :label="`${dayName(hour.weekday)} closes`"
                                    label-hidden
                                    type="time"
                                />
                            </div>
                            <span v-else class="flex-1 font-mono text-13 text-ink-3">Closed</span>

                            <Button variant="secondary" @click="toggleDay(hour)">
                                {{ hour.open ? 'Close' : 'Open' }}
                            </Button>

                            <FieldError
                                v-if="errors[`hours.${at}.end_time`] || errors[`hours.${at}.start_time`]"
                                class="w-full"
                                :message="errors[`hours.${at}.end_time`] ?? errors[`hours.${at}.start_time`]"
                            />
                        </div>
                    </div>

                    <p class="mt-2 text-12 text-ink-2">
                        {{ openDays === 0 ? 'Every day is closed — open at least one.' : `Open ${openDays} of 7 days.` }}
                    </p>
                </fieldset>
            </section>

            <section v-else-if="step === 'business'">
                <h1 class="text-34 tracking-34">Where and when you trade</h1>
                <p class="mt-2 max-w-measure text-14 text-ink-2">
                    The timezone decides what time a booking is in. The rest appears on confirmations and on your
                    booking page.
                </p>

                <div class="mt-12 max-w-measure space-y-4">
                    <Combobox
                        v-model="form.timezone"
                        label="Timezone"
                        :options="timezones.map((zone) => ({ value: zone, label: zone }))"
                        :error="errors.timezone"
                        required
                    />
                    <TextInput v-model="form.phone" label="Phone" type="tel" :error="errors.phone" />
                    <TextInput
                        v-model="form.address_line_1"
                        label="Address"
                        :error="errors.address_line_1"
                        autocomplete="address-line1"
                    />
                    <TextInput
                        v-model="form.address_line_2"
                        label="Address line 2"
                        :error="errors.address_line_2"
                        autocomplete="address-line2"
                    />
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextInput v-model="form.city" label="Town or city" :error="errors.city" />
                        <TextInput v-model="form.postcode" label="Postcode" :error="errors.postcode" />
                    </div>
                </div>

                <div class="mt-12 max-w-measure">
                    <BookingModeFields
                        v-model:booking-mode="form.booking_mode"
                        v-model:request-requires-deposit="form.request_requires_deposit"
                        :error="errors.booking_mode"
                    />
                </div>
            </section>

            <section v-else-if="step === 'services'">
                <h1 class="text-34 tracking-34">Add your first service</h1>
                <p class="mt-2 max-w-measure text-14 text-ink-2">
                    The deposit is taken when the customer books and comes off the balance on the day.
                </p>

                <div class="mt-12 grid gap-12 lg:grid-cols-[minmax(0,1fr)_18rem]">
                    <div class="space-y-6">
                        <TextInput
                            v-model="form.service.name"
                            label="Service name"
                            :error="errors.name"
                            required
                            autofocus
                        />

                        <div class="grid gap-4 sm:grid-cols-2">
                            <TextInput
                                v-model="form.service.duration_minutes"
                                label="Duration"
                                type="number"
                                suffix="min"
                                :error="localErrors.duration ?? errors.duration_minutes"
                                required
                                @focusout="touch('duration')"
                            />
                            <TextInput
                                v-model="form.service.price"
                                label="Price"
                                prefix="£"
                                mono
                                :error="localErrors.price ?? errors.price"
                                required
                                @focusout="touch('price')"
                            />
                        </div>

                        <div>
                            <TextInput
                                v-model="form.service.deposit_amount"
                                label="Deposit taken at booking"
                                prefix="£"
                                mono
                                :error="localErrors.deposit ?? errors.deposit_amount"
                                @focusout="touch('deposit')"
                            />
                            <p class="mt-2 max-w-measure text-12 text-ink-2">
                                Deposits are the single biggest lever on no-shows. £10 is a common starting point for a
                                90 minute slot. Use 0 if you would rather not take one.
                            </p>
                        </div>
                    </div>

                    <div>
                        <p class="eyebrow mb-3">What the customer sees</p>
                        <div class="rounded border border-rule p-4">
                            <p class="text-20 tracking-20">{{ form.service.name || 'Your service' }}</p>
                            <p class="mt-2 font-mono text-13 text-ink-2">{{ durationInMinutes }} min</p>

                            <div class="mt-4 flex items-baseline justify-between border-t border-rule pt-3">
                                <span class="text-13 text-ink-2">Total</span>
                                <span class="numeral text-17">£{{ penceToPoundsInput(priceInPence) }}</span>
                            </div>
                            <div class="mt-2 flex items-baseline justify-between">
                                <span class="text-13 text-ink-2">Pay now to hold the slot</span>
                                <span class="numeral text-13 text-accent">
                                    £{{ penceToPoundsInput(depositInPence) }}
                                </span>
                            </div>

                            <p class="mt-4 rounded bg-paper-sunk py-2 text-center text-13 font-medium text-ink">
                                Choose a time
                            </p>
                        </div>
                    </div>
                </div>
            </section>

            <section v-else-if="step === 'staff'">
                <h1 class="text-34 tracking-34">Anyone else taking appointments?</h1>
                <p class="mt-2 max-w-measure text-14 text-ink-2">
                    Each person gets their own hours and their own column in Bookings. Skip this if you work alone —
                    you can add people any time.
                </p>

                <div class="mt-12 max-w-measure">
                    <div class="grid gap-4 sm:grid-cols-2">
                        <TextInput
                            v-model="form.staffName"
                            label="Full name"
                            placeholder="e.g. Erin MacKay"
                            :error="errors['staff.name']"
                        />
                        <TextInput
                            v-model="form.staffEmail"
                            label="Work email for the invite"
                            type="email"
                            autocomplete="off"
                            :error="errors['staff.email']"
                        />
                    </div>

                    <div v-if="hasStaffEntry" class="mt-6 border-t border-rule pt-4">
                        <Toggle
                            v-model="form.staffCanSeeContacts"
                            label="Can see every customer's contact details"
                            hint="Off means they only see appointments booked to them"
                        />
                    </div>

                    <ul v-if="staff.length > 1" class="mt-8 rule-line">
                        <li
                            v-for="member in staff"
                            :key="member.id"
                            class="flex items-baseline justify-between gap-4 border-b border-rule py-3"
                        >
                            <span class="text-14 text-ink">{{ member.name }}</span>
                            <span class="font-mono text-12 text-ink-2">
                                {{ member.is_owner ? 'You' : member.email }}
                            </span>
                        </li>
                    </ul>
                </div>
            </section>

            <section v-else-if="step === 'link'">
                <p class="eyebrow text-accent">Live now</p>
                <h1 class="mt-3 text-34 tracking-34">Share your booking link</h1>
                <p class="mt-2 max-w-measure text-14 text-ink-2">
                    Put this in your Instagram bio and reply to enquiries with it. Every booking made through it takes
                    the deposit and appears in your diary straight away.
                </p>

                <div class="mt-12 flex flex-wrap items-start gap-12">
                    <div class="min-w-64 flex-1">
                        <div class="flex items-center gap-3 rounded border border-rule px-3 py-2">
                            <span ref="urlEl" class="flex-1 truncate font-mono text-14 text-ink">{{ displayUrl }}</span>
                            <Button variant="accent-solid" @click="copy">{{ copyLabel }}</Button>
                        </div>

                        <p v-if="copyState === 'manual'" class="mt-2 text-12 text-ink-2">
                            Your browser would not let us copy it — the link is selected, so press
                            <kbd>Ctrl</kbd>/<kbd>Cmd</kbd> + <kbd>C</kbd>.
                        </p>

                        <p class="mt-3 max-w-measure text-13 text-ink-2">
                            Next: your first customer books, you get a text, and the slot is held. Nothing else to
                            switch on.
                        </p>
                    </div>

                    <div>
                        <div class="rounded border border-rule p-3">
                            <div v-if="qr" class="qr size-32" aria-hidden="true" v-html="qr" />
                            <p v-else class="size-32 text-12 text-ink-3">Code unavailable</p>
                        </div>
                        <p class="mt-2 text-center text-12 text-ink-2">Print for the shop window</p>
                    </div>
                </div>
            </section>
        </div>

        <div
            class="sticky bottom-0 flex items-center justify-between gap-6 border-t border-rule bg-paper px-6 py-4 md:px-12"
        >
            <Button v-if="!isFirst" variant="secondary" :disabled="saving" @click="goBack">Back</Button>
            <span v-else />

            <div class="flex items-center gap-6">
                <span class="numeral text-12 text-ink-2">Step {{ index + 1 }} of {{ total }}</span>
                <Button variant="accent-solid" :loading="saving" :disabled="saving" @click="onNext">
                    {{ nextLabel }}
                </Button>
            </div>
        </div>
    </div>
</template>

<style scoped>
.qr :deep(svg) {
    display: block;
    width: 100%;
    height: auto;
}
</style>
