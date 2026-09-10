<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import BookingModeFields from '@/Components/BookingModeFields.vue';
import Callout from '@/Components/ui/Callout.vue';
import Combobox from '@/Components/ui/Combobox.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import RadioGroup from '@/Components/ui/RadioGroup.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Toggle from '@/Components/ui/Toggle.vue';
import { penceToPoundsInput, poundsInputToPence } from '@/lib/money';
import { weekdays } from '@/lib/weekdays';
import type { FormDataConvertible } from '@inertiajs/core';
import { Head, router, usePage } from '@inertiajs/vue3';
import QRCode from 'qrcode';
import { computed, onMounted, onUnmounted, ref, watch } from 'vue';

/**
 * Setting up a business: five steps, one component, one page.
 *
 * **The step is client state, and the server is the record.** `props.step` says
 * where the person actually is according to what has been saved; `step` below
 * is what is on screen. They are the same on load and after every save, and
 * they come apart in exactly one place — pressing Back, which moves the screen
 * without asking the server anything. That is the whole reason for the split:
 * Back has to be free, and a Back that costs a round trip is a Back that can
 * lose what you typed on the step you are leaving.
 *
 * Everything a person has entered lives in `form`, one object for all five
 * steps, created once. So going back to step one and forward again finds step
 * four exactly as it was left, including the parts that were never saved —
 * which is what the staff step needs, since skipping it saves nothing at all.
 *
 * **A refresh is the other half of that bargain.** `form` is not persisted, so
 * reopening the tab rebuilds it from props — that is, from the last step the
 * server accepted. A step you completed comes back filled in; a step you were
 * mid-way through comes back at its defaults. Saving on continue is what makes
 * the first of those true, and it is why each step posts rather than the flow
 * posting once at the end.
 */

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

/*
 * Set when a save did not reach the server or came back as something other than
 * a validation failure — a dropped connection, a 500. Distinct from `errors`,
 * which is the server telling us it read the request and disagreed with it. The
 * banner is retryable and nothing is cleared, so the fix is one click and the
 * form is exactly as it was left.
 */
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

/* ---------------------------------------------------------------- chrome -- */

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

/* -------------------------------------------------------------- the save -- */

/**
 * One submit for every step. The server redirects to the next step and the
 * screen follows it; on a validation failure the visit comes back to the same
 * page with `errors` populated and `step` untouched, so the person stays where
 * they are with their answers still in the fields.
 */
const submit = (method: 'patch' | 'post', url: string, data: Record<string, FormDataConvertible>) => {
    const send = () => {
        saving.value = true;
        transportError.value = '';

        router[method](url, data, {
            preserveScroll: true,
            preserveState: true,
            /*
             * The step comes off the page that came back, not off `props` —
             * `props` still holds the old value inside this callback. The
             * watcher below cannot stand in for it either: going Back into a
             * step and forward again returns the same step the server last
             * sent, and a watcher does not fire on an unchanged value.
             */
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

/*
 * `props.step` is the server's answer to "where are they". After a save it
 * changes to the step the controller redirected to, and the screen follows.
 * Back moves `step` on its own and does not touch this, which is the one time
 * the two are allowed to disagree.
 */
watch(
    () => props.step,
    (value) => {
        step.value = value;
    },
);

/*
 * A transport failure never arrives through `onError` — that is validation.
 * These two events are Inertia's "the request did not come back as a page":
 * a thrown error, or a response that was not an Inertia response at all.
 */
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

/* --------------------------------------------------------------- basics --- */

const slugState = ref<'idle' | 'checking' | 'free' | 'taken'>('idle');
const slugSuggestion = ref<string | null>(null);
/** True once the slug has been typed in, after which the name stops driving it. */
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

/*
 * The name drives the slug until somebody edits the slug, and then it stops.
 * A field that keeps rewriting itself under you is worse than one that never
 * fills itself in, and "Paws & Whiskers" typing over `paws-and-whiskers-2`
 * — the suggestion you just accepted — is exactly that.
 */
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
                /*
                 * The check is advisory. If it cannot run — offline, throttled
                 * — the field goes quiet rather than red: the save validates
                 * against the unique index anyway, and a scary message about a
                 * name that is probably fine is worse than no message.
                 */
                slugState.value = 'idle';
            }
        }, 350);
    },
);

onUnmounted(() => clearTimeout(slugTimer));

/*
 * The trade picker. The mockup drew this as a grid of six cards, but a card
 * grid here is a radio group wearing a costume: one answer, from a set small
 * enough to read in full, saved with the rest of the step. `ui/RadioGroup` is
 * that control, it carries the per-option note as its `hint`, and it arrives
 * with the keyboard behaviour and the error binding six hand-rolled `<button>`s
 * would each have had to reimplement.
 */
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

/* ------------------------------------------------------------- business --- */

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

/* ------------------------------------------------------------- services --- */

const priceInPence = computed(() => poundsInputToPence(form.value.service.price));
const depositInPence = computed(() => poundsInputToPence(form.value.service.deposit_amount));
const durationInMinutes = computed(() => parseInt(form.value.service.duration_minutes, 10) || 0);

/*
 * Checked on blur as well as on submit, because the deposit and the price are
 * two fields whose relationship only exists once both are filled in — and the
 * moment to say "that is more than the price" is while the person is still
 * looking at the two numbers, not after they have pressed Continue.
 */
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

/* ---------------------------------------------------------------- staff --- */

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

/*
 * Skip does not clear the fields. Somebody who types half a name, skips, then
 * comes back through Back finds it still there — `form` outlives the step, and
 * the only thing skipping does is decline to send it.
 */
const skip = () => submitStaff(true);

/* ----------------------------------------------------------------- link --- */

const liveBookingUrl = computed(() =>
    props.bookingUrl.replace(/\/[^/]*$/, `/${form.value.slug || props.basics.slug}`),
);

const displayUrl = computed(() => liveBookingUrl.value.replace(/^https?:\/\//, ''));

const copyState = ref<'idle' | 'copied' | 'manual'>('idle');
const urlEl = ref<HTMLElement | null>(null);

/**
 * Copy, and a real answer when copying is not available.
 *
 * `navigator.clipboard` is undefined on a page that is not a secure context and
 * throws when the browser refuses the permission — both of which are ordinary
 * rather than exotic, and neither of which should end with a button that looks
 * like it worked. The fallback selects the URL so the keyboard shortcut is one
 * press away, and the label says so.
 */
const copy = async () => {
    try {
        if (!navigator.clipboard?.writeText) {
            throw new Error('unavailable');
        }

        await navigator.clipboard.writeText(liveBookingUrl.value);
        copyState.value = 'copied';
        setTimeout(() => {
            copyState.value = 'idle';
        }, 1600);
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

const copyLabel = computed(() =>
    copyState.value === 'copied' ? 'Copied' : copyState.value === 'manual' ? 'Selected' : 'Copy',
);

const qr = ref('');

/*
 * A real encoded QR, drawn at render time from the URL the person is actually
 * being given. The colours are read off the document rather than written here,
 * because `qrcode` wants literal hex and every literal colour in this codebase
 * comes from `tokens.css`.
 */
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

/*
 * A slug collision is only reported at the end if somebody took the address
 * while this person was filling in the middle three steps. It is not fixable
 * here — the field and its availability check live on step one — so the error
 * carries the screen back with it.
 */
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

            <!-- ------------------------------------------------- 1. basics -->
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

            <!-- ----------------------------------------------- 2. business -->
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

            <!-- ----------------------------------------------- 3. services -->
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

            <!-- -------------------------------------------------- 4. staff -->
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

            <!-- --------------------------------------------------- 5. link -->
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
/*
 * `qrcode` emits a bare `<svg viewBox="…">` with no width or height, which
 * without this sizes itself as a 300x150 replaced element rather than filling
 * the box it was given.
 */
.qr :deep(svg) {
    display: block;
    width: 100%;
    height: auto;
}
</style>
