<script setup lang="ts">
import OnboardingLayout from '@/Layouts/OnboardingLayout.vue';
import Button from '@/Components/ui/Button.vue';
import FieldError from '@/Components/ui/FieldError.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Select from '@/Components/ui/Select.vue';
import WeeklyHoursGrid from '@/Components/WeeklyHoursGrid.vue';
import { penceToPoundsInput, poundsInputToPence } from '@/lib/money';
import type { AvailabilityRange, UserRole } from '@/types/models';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

const props = defineProps<{
    step: string;
    completedSteps: string[];
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
}>();

const businessForm = useForm({
    timezone: props.business.timezone,
    phone: props.business.phone ?? '',
    address_line_1: props.business.address_line_1 ?? '',
    address_line_2: props.business.address_line_2 ?? '',
    city: props.business.city ?? '',
    postcode: props.business.postcode ?? '',
});

const serviceRows = ref(
    props.services.map((service) => ({
        ...service,
        price_input: penceToPoundsInput(service.price),
        deposit_input: penceToPoundsInput(service.deposit_amount),
    })),
);

const extraStaff = ref(
    props.staff
        .filter((person) => !person.is_owner)
        .map((person) => ({ name: person.name, email: person.email })),
);

if (extraStaff.value.length === 0) {
    extraStaff.value.push({ name: '', email: '' });
}

const hours = ref<AvailabilityRange[]>([...props.hours]);

const serviceForm = useForm({
    services: [] as Array<{
        id: number | null;
        name: string;
        duration_minutes: number;
        price: number;
        deposit_amount: number;
    }>,
});

const staffForm = useForm({
    staff: [] as { name: string; email: string }[],
});

const hoursForm = useForm({
    rules: [] as AvailabilityRange[],
});

const addService = () => {
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
};

const saveBusiness = () => businessForm.patch(route('onboarding.business'));

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

const saveStaff = () => {
    staffForm.staff = extraStaff.value.filter((row) => row.name && row.email);
    staffForm.patch(route('onboarding.staff'));
};

const saveHours = () => {
    hoursForm.rules = hours.value;
    hoursForm.patch(route('onboarding.hours'));
};

const owner = computed(() => props.staff.filter((person) => person.is_owner));
</script>

<template>
    <OnboardingLayout :step="step" :completed-steps="completedSteps">
        <Head title="Set up your business" />

        <form v-if="step === 'business'" class="space-y-4 rounded border border-rule bg-white p-6" @submit.prevent="saveBusiness">
            <Select v-model="businessForm.timezone" label="Timezone">
                <option v-for="zone in timezones" :key="zone" :value="zone">{{ zone }}</option>
            </Select>
            <TextInput v-model="businessForm.phone" label="Phone" />
            <TextInput v-model="businessForm.address_line_1" label="Address line 1" />
            <TextInput v-model="businessForm.address_line_2" label="Address line 2" />
            <div class="grid gap-4 md:grid-cols-2">
                <TextInput v-model="businessForm.city" label="City" />
                <TextInput v-model="businessForm.postcode" label="Postcode" />
            </div>
            <Button type="submit" :disabled="businessForm.processing">Save and continue</Button>
        </form>

        <div v-else-if="step === 'services'" class="space-y-4 rounded border border-rule bg-white p-6">
            <p class="text-14 text-ink-2">These are your services. Price is what the client pays; deposit is taken online.</p>
            <div
                v-for="(row, index) in serviceRows"
                :key="index"
                class="grid gap-3 border-b border-rule py-4 md:grid-cols-12"
            >
                <div class="md:col-span-4">
                    <TextInput v-model="row.name" :label="'Service ' + (index + 1)" />
                </div>
                <div class="md:col-span-2">
                    <TextInput v-model="row.duration_minutes" type="number" label="Minutes" />
                </div>
                <div class="md:col-span-2">
                    <TextInput v-model="row.price_input" label="Price" />
                </div>
                <div class="md:col-span-2">
                    <TextInput v-model="row.deposit_input" label="Deposit" />
                </div>
                <div class="self-end md:col-span-2">
                    <Button
                        variant="ghost"
                        :aria-label="`Remove ${row.name || 'this service'}`"
                        @click="serviceRows.splice(index, 1)"
                    >
                        Remove
                    </Button>
                </div>
            </div>
            <div class="flex gap-2">
                <Button variant="secondary" @click="addService">Add a service</Button>
                <Button :disabled="serviceForm.processing" @click="saveServices">Save and continue</Button>
            </div>
            <FieldError :message="serviceForm.errors.services" />
        </div>

        <div v-else-if="step === 'staff'" class="space-y-4 rounded border border-rule bg-white p-6">
            <p class="text-14 text-ink-2">
                {{ owner[0]?.name }} is already on the team as the owner.
            </p>
            <div v-for="(row, index) in extraStaff" :key="index" class="grid gap-3 md:grid-cols-2">
                <TextInput v-model="row.name" :label="'Name ' + (index + 1)" />
                <TextInput v-model="row.email" type="email" :label="'Email ' + (index + 1)" />
            </div>
            <div class="flex gap-2">
                <Button variant="secondary" @click="extraStaff.push({ name: '', email: '' })">Add staff</Button>
                <Button :disabled="staffForm.processing" @click="saveStaff">Save and continue</Button>
            </div>
        </div>

        <div v-else class="space-y-4 rounded border border-rule bg-white p-6">
            <p class="text-14 text-ink-2">Set when each person takes bookings.</p>
            <WeeklyHoursGrid v-model="hours" :staff="staff" />
            <Button :disabled="hoursForm.processing" @click="saveHours">Finish setup</Button>
            <p v-if="hoursForm.errors.rules" class="text-13 text-danger">{{ hoursForm.errors.rules }}</p>
        </div>
    </OnboardingLayout>
</template>
