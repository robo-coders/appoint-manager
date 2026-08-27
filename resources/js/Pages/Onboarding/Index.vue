<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Combobox from '@/Components/ui/Combobox.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import OnboardingLayout from '@/Layouts/OnboardingLayout.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import Select from '@/Components/ui/Select.vue';
import type { Step } from '@/Components/ui/StepProgress.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import WeeklyHoursGrid from '@/Components/WeeklyHoursGrid.vue';
import { penceToPoundsInput, poundsInputToPence } from '@/lib/money';
import type { AvailabilityRange, UserRole } from '@/types/models';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Steps two to five. `OnboardingLayout` owns the page; this owns the four forms.
 *
 * What changed, and why each one was wrong:
 *
 *   - **No cards.** Every step was `rounded border border-rule bg-white p-6` —
 *     a white box on paper, four times, inside a layout that was itself a
 *     centred column. Containment is a signal and DESIGN.md spends it once; a
 *     step that is the only thing on its screen does not need a border to say
 *     it is the thing on the screen. Sections are hairlines now.
 *
 *   - **Services and Staff were `Service 1`, `Minutes`, `Name 2`, `Email 2`.**
 *     Numbering a label is what you do when rows have no identity. They are
 *     rows in a list; the list says which row is which by being a list.
 *
 *   - **The timezone was a 400-option `<select>`.** `ui/Combobox` exists for
 *     exactly this and Settings already uses it, so onboarding was the one
 *     screen in the product where picking a timezone was a scroll.
 *
 *   - **Staff had no empty state.** It pushed a blank row in on mount, so a
 *     salon of one was asked to fill in a second person's details in order to
 *     say there is no second person. There is a stated empty state and an
 *     explicit "Add someone".
 *
 *   - **The last step handed over an empty diary.** See `firstBooking` below.
 */
const props = defineProps<{
    step: string;
    completedSteps: string[];
    steps: Step[];
    timezones: string[];
    business: {
        timezone: string;
        phone: string | null;
        address_line_1: string | null;
        address_line_2: string | null;
        city: string | null;
        postcode: string | null;
    };
    services: Array<{
        id: number | null;
        name: string;
        duration_minutes: number;
        price: number;
        deposit_amount: number;
        sort_order: number;
    }>;
    staff: Array<{
        id: number;
        name: string;
        email: string;
        role: UserRole;
        is_owner: boolean;
    }>;
    hours: AvailabilityRange[];
    /** Tomorrow, in the tenant's own timezone. Built in PHP — see the controller. */
    firstBookingDefault: string;
}>();

const heading = computed(
    () =>
        ({
            business: {
                title: 'Where you are',
                lede: 'Your address goes on confirmations and reminders, and the timezone decides what 09:00 means. Everything here can change later.',
            },
            services: {
                title: 'What you do',
                lede: 'These are what clients pick from when they book. A deposit is held when they book and comes off the price on the day — leave it at zero and they simply pay at the end.',
            },
            staff: {
                title: 'Who does it',
                lede: 'Anyone who takes appointments needs to be here, so the diary knows whose day is whose.',
            },
            hours: {
                title: 'When you are open',
                lede: 'Clients can only book inside these hours. Set the usual week — one-off closures are time off, later.',
            },
        })[props.step] ?? { title: 'Set up your business' },
);

/* ---------------------------------------------------------------- business */

const businessForm = useForm({
    timezone: props.business.timezone,
    phone: props.business.phone ?? '',
    address_line_1: props.business.address_line_1 ?? '',
    address_line_2: props.business.address_line_2 ?? '',
    city: props.business.city ?? '',
    postcode: props.business.postcode ?? '',
});

const timezoneOptions = computed(() => props.timezones.map((zone) => ({ value: zone, label: zone })));

const saveBusiness = () => businessForm.patch(route('onboarding.business'));

/* ---------------------------------------------------------------- services */

const serviceRows = ref(
    props.services.map((service) => ({
        ...service,
        price_input: penceToPoundsInput(service.price),
        deposit_input: penceToPoundsInput(service.deposit_amount),
    })),
);

const serviceForm = useForm({
    services: [] as Array<{
        id: number | null;
        name: string;
        duration_minutes: number;
        price: number;
        deposit_amount: number;
    }>,
});

const addService = () =>
    serviceRows.value.push({
        id: null,
        name: '',
        duration_minutes: 60,
        price: 0,
        deposit_amount: 0,
        sort_order: serviceRows.value.length,
        price_input: '0.00',
        deposit_input: '0.00',
    });

const saveServices = () => {
    serviceForm.services = serviceRows.value.map((row) => ({
        id: row.id,
        name: row.name,
        duration_minutes: Number(row.duration_minutes),
        price: poundsInputToPence(row.price_input),
        deposit_amount: poundsInputToPence(row.deposit_input),
    }));
    serviceForm.patch(route('onboarding.services'));
};

/* ------------------------------------------------------------------- staff */

const owner = computed(() => props.staff.find((person) => person.is_owner));

/*
 * No blank row on mount. Most salons setting this up are one person, and the
 * old version asked that person to look at an empty Name and Email and work out
 * that leaving them blank was the way to say "just me".
 */
const extraStaff = ref(
    props.staff.filter((person) => !person.is_owner).map((person) => ({ name: person.name, email: person.email })),
);

const staffForm = useForm({ staff: [] as { name: string; email: string }[] });

const saveStaff = () => {
    staffForm.staff = extraStaff.value.filter((row) => row.name && row.email);
    staffForm.patch(route('onboarding.staff'));
};

/* ------------------------------------------------------------------- hours */

const hours = ref<AvailabilityRange[]>([...props.hours]);

/**
 * The last step, and the one thing it does that the old one did not.
 *
 * Finishing setup used to drop a person onto a diary with nothing in it. That
 * is the moment the whole thing stops feeling like a decision they made and
 * starts feeling like a chore they took on: an empty week, on a Tuesday night,
 * after twenty minutes of typing.
 *
 * Every salon signing up has a paper book with tomorrow already in it. So the
 * final step asks for one line of it — a name, a service, a time — and the
 * diary they land on has an appointment in it. It is optional and it says so;
 * skipping is one click and lands on the same diary, empty, which is honest.
 *
 * It is not a second idea on the screen. The idea of this step is "you are
 * open", and when you are open the two things that are true are when, and who
 * is first.
 */
const wantsFirstBooking = ref(false);

const hoursForm = useForm({
    rules: [] as AvailabilityRange[],
    first_booking: null as null | {
        customer_name: string;
        customer_email: string;
        service_id: number | string;
        staff_id: number | string;
        starts_at: string;
    },
});

const bookableStaff = computed(() => props.staff.map((person) => ({ value: person.id, label: person.name })));
const serviceOptions = computed(() =>
    props.services.filter((service) => service.name).map((service) => ({ value: service.id ?? 0, label: service.name })),
);

/*
 * The ids start as the empty string rather than as null, because `ui/Select`
 * binds a `<select>` and a `<select>` has no null. Its first option is selected
 * on mount either way; starting at null only meant the model and the control
 * disagreed about what was chosen until somebody touched it.
 */
const firstBooking = ref({
    customer_name: '',
    customer_email: '',
    service_id: serviceOptions.value[0]?.value ?? ('' as number | string),
    staff_id: owner.value?.id ?? bookableStaff.value[0]?.value ?? ('' as number | string),
    starts_at: props.firstBookingDefault,
});

const saveHours = () => {
    hoursForm.rules = hours.value;
    hoursForm.first_booking =
        wantsFirstBooking.value && (firstBooking.value.customer_name || firstBooking.value.customer_email)
            ? { ...firstBooking.value }
            : null;
    hoursForm.patch(route('onboarding.hours'));
};
</script>

<template>
    <OnboardingLayout
        :step="step"
        :completed-steps="completedSteps"
        :steps="steps"
        :title="heading.title"
        :lede="heading.lede"
    >
        <Head :title="heading.title" />

        <!-- ============================================================ business -->
        <form v-if="step === 'business'" class="max-w-auth-form space-y-4" @submit.prevent="saveBusiness">
            <Combobox
                v-model="businessForm.timezone"
                label="Timezone"
                :options="timezoneOptions"
                :error="businessForm.errors.timezone"
                required
            />
            <TextInput
                v-model="businessForm.phone"
                type="tel"
                label="Phone"
                hint="Clients see this on their confirmation."
                :error="businessForm.errors.phone"
            />
            <TextInput
                v-model="businessForm.address_line_1"
                label="Address"
                :error="businessForm.errors.address_line_1"
            />
            <TextInput
                v-model="businessForm.address_line_2"
                label="Address line 2"
                :error="businessForm.errors.address_line_2"
            />
            <div class="grid gap-4 sm:grid-cols-2">
                <TextInput v-model="businessForm.city" label="Town or city" :error="businessForm.errors.city" />
                <TextInput v-model="businessForm.postcode" label="Postcode" :error="businessForm.errors.postcode" />
            </div>
            <div class="pt-2">
                <Button type="submit" :loading="businessForm.processing">Save and continue</Button>
            </div>
        </form>

        <!-- ============================================================ services -->
        <form v-else-if="step === 'services'" class="space-y-4" @submit.prevent="saveServices">
            <EmptyState
                v-if="serviceRows.length === 0"
                title="No services yet"
                description="You need at least one before clients can book anything."
                action-label="Add the first one"
                @action="addService"
            />

            <ul v-else class="border-t border-t-rule">
                <li v-for="(row, index) in serviceRows" :key="index" class="border-b border-b-rule py-4">
                    <!--
                        Three columns at 375, not one. Stacked, a salon with the
                        four default services scrolled through sixteen full-width
                        fields to get to the button — and Minutes, Price and
                        Deposit are two-to-five characters each, so a field the
                        width of the phone is mostly empty field.
                    -->
                    <div class="grid grid-cols-3 gap-3 sm:grid-cols-12">
                        <div class="col-span-3 sm:col-span-5">
                            <TextInput v-model="row.name" label="Name" placeholder="Full groom" />
                        </div>
                        <div class="sm:col-span-2">
                            <TextInput v-model="row.duration_minutes" type="number" label="Minutes" />
                        </div>
                        <div class="sm:col-span-2">
                            <TextInput v-model="row.price_input" label="Price" prefix="£" mono />
                        </div>
                        <div class="sm:col-span-2">
                            <TextInput v-model="row.deposit_input" label="Deposit" prefix="£" mono />
                        </div>
                        <div class="col-span-3 flex items-end sm:col-span-1">
                            <QuietAction
                                class="-ml-3"
                                :aria-label="`Remove ${row.name || 'this service'}`"
                                @click="serviceRows.splice(index, 1)"
                            >
                                Remove
                            </QuietAction>
                        </div>
                    </div>
                </li>
            </ul>

            <FieldError :message="serviceForm.errors.services" />

            <div class="flex flex-wrap items-center gap-4 pt-2">
                <Button type="submit" :loading="serviceForm.processing" :disabled="serviceRows.length === 0">
                    Save and continue
                </Button>
                <QuietAction v-if="serviceRows.length > 0" @click="addService">Add another service</QuietAction>
            </div>
        </form>

        <!-- =============================================================== staff -->
        <form v-else-if="step === 'staff'" class="max-w-3xl space-y-4" @submit.prevent="saveStaff">
            <Callout :title="owner?.name">
                You are on the team already, as the owner. Add anyone else who takes appointments — they get their own
                sign-in and their own column in the diary.
            </Callout>

            <EmptyState
                v-if="extraStaff.length === 0"
                title="Just you, for now"
                description="That is a perfectly normal answer. You can add people whenever you take someone on."
                action-label="Add someone"
                @action="extraStaff.push({ name: '', email: '' })"
            />

            <ul v-else class="border-t border-t-rule">
                <li v-for="(row, index) in extraStaff" :key="index" class="border-b border-b-rule py-4">
                    <div class="grid gap-3 sm:grid-cols-12">
                        <div class="sm:col-span-5">
                            <TextInput v-model="row.name" label="Name" />
                        </div>
                        <div class="sm:col-span-6">
                            <TextInput v-model="row.email" type="email" label="Email" autocomplete="off" />
                        </div>
                        <div class="flex items-end sm:col-span-1">
                            <QuietAction
                                class="-ml-3"
                                :aria-label="`Remove ${row.name || 'this person'}`"
                                @click="extraStaff.splice(index, 1)"
                            >
                                Remove
                            </QuietAction>
                        </div>
                    </div>
                </li>
            </ul>

            <FieldError :message="staffForm.errors.staff" />

            <div class="flex flex-wrap items-center gap-4 pt-2">
                <Button type="submit" :loading="staffForm.processing">Save and continue</Button>
                <QuietAction v-if="extraStaff.length > 0" @click="extraStaff.push({ name: '', email: '' })">
                    Add another person
                </QuietAction>
            </div>
        </form>

        <!-- =============================================================== hours -->
        <form v-else class="space-y-6" @submit.prevent="saveHours">
            <WeeklyHoursGrid v-model="hours" :staff="staff" />
            <FieldError :message="hoursForm.errors.rules" />

            <!--
                The first appointment. A hairline section, not a card: it belongs
                to this step rather than sitting beside it.
            -->
            <section class="border-t border-t-rule pt-6">
                <h2 class="text-15">One last thing, and it is optional</h2>
                <p class="mt-1 max-w-measure text-14 leading-body text-ink-2">
                    If you already know who is in first, put them in now and the diary you land on will have them in
                    it. Otherwise skip it — nothing here is a commitment.
                </p>

                <QuietAction v-if="!wantsFirstBooking" class="-ml-3 mt-4" @click="wantsFirstBooking = true">
                    Add the first appointment
                </QuietAction>

                <div v-else class="mt-4 grid max-w-3xl gap-3 sm:grid-cols-12">
                    <div class="sm:col-span-6">
                        <TextInput
                            v-model="firstBooking.customer_name"
                            label="Client name"
                            :error="hoursForm.errors['first_booking.customer_name']"
                        />
                    </div>
                    <div class="sm:col-span-6">
                        <TextInput
                            v-model="firstBooking.customer_email"
                            type="email"
                            label="Their email"
                            hint="So the confirmation and the reminder can reach them."
                            :error="hoursForm.errors['first_booking.customer_email']"
                        />
                    </div>
                    <div class="sm:col-span-4">
                        <Select
                            v-model="firstBooking.service_id"
                            label="Service"
                            :options="serviceOptions"
                            :error="hoursForm.errors['first_booking.service_id']"
                        />
                    </div>
                    <div class="sm:col-span-3">
                        <Select
                            v-model="firstBooking.staff_id"
                            label="With"
                            :options="bookableStaff"
                            :error="hoursForm.errors['first_booking.staff_id']"
                        />
                    </div>
                    <div class="sm:col-span-5">
                        <TextInput
                            v-model="firstBooking.starts_at"
                            type="datetime-local"
                            label="When"
                            :error="hoursForm.errors['first_booking.starts_at']"
                        />
                    </div>
                    <div class="sm:col-span-12">
                        <FieldError :message="hoursForm.errors.first_booking" />
                        <QuietAction @click="wantsFirstBooking = false">Never mind, skip it</QuietAction>
                    </div>
                </div>
            </section>

            <div class="pt-2">
                <Button type="submit" :loading="hoursForm.processing">Finish and open the diary</Button>
            </div>
        </form>
    </OnboardingLayout>
</template>
