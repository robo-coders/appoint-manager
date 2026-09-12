<script setup lang="ts">
import { DEFAULT_STAFF_COLOUR } from '@/lib/staffColour';
import AppLayout from '@/Layouts/AppLayout.vue';
import AddCard from '@/Components/ui/AddCard.vue';
import Badge from '@/Components/ui/Badge.vue';
import StaffColourField from '@/Components/ui/StaffColourField.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import Menu from '@/Components/ui/Menu.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { StaffRecord } from '@/types/models';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type StaffRow = StaffRecord & {
    initial: string;
    role_label: string;
    hours: string;
    weekly_hours: string | null;
    booked_this_week: number;
    service_ids: number[];
    daily_hours: number[];
};

const props = defineProps<{
    staff: StaffRow[];
    services: Array<{ id: number; name: string }>;
}>();

const page = usePage();
const sheetOpen = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    email: '',
    colour: DEFAULT_STAFF_COLOUR,
    is_bookable: true,
    is_active: true,
    can_see_customer_contacts: true,
    service_ids: [] as number[],
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.colour = DEFAULT_STAFF_COLOUR;
    form.is_bookable = true;
    form.is_active = true;
    form.can_see_customer_contacts = true;
    form.service_ids = props.services.map((service) => service.id);
    sheetOpen.value = true;
};

const editingOwner = computed(
    () => props.staff.find((person) => person.id === editingId.value)?.role === 'owner',
);

const openEdit = (person: StaffRow) => {
    editingId.value = person.id;
    form.name = person.name;
    form.email = person.email;
    form.colour = person.colour ?? DEFAULT_STAFF_COLOUR;
    form.is_bookable = person.is_bookable;
    form.is_active = person.is_active;
    form.can_see_customer_contacts = person.can_see_customer_contacts;
    form.service_ids = [...person.service_ids];
    sheetOpen.value = true;
};

const toggleService = (id: number, checked: boolean) => {
    form.service_ids = checked ? [...form.service_ids, id] : form.service_ids.filter((each) => each !== id);
};

const nothingSelected = computed(() => props.services.length > 0 && form.service_ids.length === 0);

const submit = () => {
    if (editingId.value) {
        form.patch(route('staff.update', editingId.value), {
            onSuccess: () => (sheetOpen.value = false),
        });
    } else {
        form.post(route('staff.store'), {
            onSuccess: () => (sheetOpen.value = false),
        });
    }
};

const deactivate = (person: StaffRecord) => {
    router.patch(route('staff.update', person.id), { is_active: false });
};

const openHours = (person: StaffRecord) => router.get(route('availability.index'), { staff: person.id });

const load = (count: number) => `${count} booked this week`;

const active = computed(() => props.staff.filter((person) => person.is_active).length);

const WEEK_DAYS = ['M', 'T', 'W', 'T', 'F', 'S', 'S'] as const;
const WEEK_DAY_NAMES = ['Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday'] as const;

const formatHours = (hours: number): string => {
    const rounded = Math.round(hours * 10) / 10;

    return Number.isInteger(rounded) ? String(rounded) : rounded.toFixed(1);
};

const hasSchedule = (person: StaffRow): boolean => person.weekly_hours !== null;

const DAY_RUN =
    /(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun)(?:–(?:Mon|Tue|Wed|Thu|Fri|Sat|Sun))? · (?!closed )([^·]+)/g;

const scheduleVaries = (person: StaffRow): boolean => {
    if (!hasSchedule(person)) {
        return false;
    }

    const times = [...person.hours.matchAll(DAY_RUN)].map((match) => match[1].trim());

    return new Set(times).size > 1;
};

const daysWorked = (person: StaffRow): number => person.daily_hours.filter((hours) => hours > 0).length;

const dayTip = (person: StaffRow, hours: number): string => {
    if (!hasSchedule(person)) {
        return 'No hours';
    }

    return hours === 0 ? 'Off' : `${formatHours(hours)}h`;
};

const DAY_SCALE = 8;

const barHeight = (person: StaffRow, hours: number): string => {
    if (hours <= 0) {
        return '0%';
    }

    const max = Math.max(DAY_SCALE, ...person.daily_hours);

    return `${Math.min(100, (hours / max) * 100)}%`;
};

const hoveredBar = ref<string | null>(null);

const barKey = (id: number, index: number) => `${id}-${index}`;

const canOpenMenu = (person: StaffRow) => person.is_active && person.id !== page.props.auth.user?.id;

const peopleCount = computed(() => props.staff.length);

const weekCoveredHours = computed(() =>
    props.staff.reduce((total, person) => total + person.daily_hours.reduce((sum, hours) => sum + hours, 0), 0),
);

const weekBooked = computed(() => props.staff.reduce((total, person) => total + person.booked_this_week, 0));
</script>

<template>
    <AppLayout>
        <Head title="Staff" />
        <div class="max-w-record">
        <PageHeader title="Staff" description="People who work in the business.">
            <Button @click="openCreate">Add staff</Button>
        </PageHeader>

        <p
            v-if="staff.length > 0"
            class="mb-4 flex flex-wrap items-center gap-2 text-13 text-ink-2"
        >
            <span aria-hidden="true" class="size-1.5 shrink-0 rounded bg-green"></span>
            <span class="font-medium text-ink">
                <span class="numeral">{{ peopleCount }}</span>
                {{ peopleCount === 1 ? 'person' : 'people' }}
            </span>
            <span aria-hidden="true" class="text-ink-4">·</span>
            <span>
                <span class="font-medium text-ink">
                    <span class="numeral">{{ formatHours(weekCoveredHours) }} hours</span>
                </span>
                covered this week
            </span>
            <span aria-hidden="true" class="text-ink-4">·</span>
            <span class="numeral">{{ weekBooked }} booked</span>
        </p>

        <EmptyState
            v-if="staff.length === 0"
            title="Nobody here yet"
            description="Add the people who take appointments and they become columns in the diary."
            action-label="Add someone"
            @action="openCreate"
        />

        <div v-else class="border-t border-t-rule-strong">
            <ul>
                <li
                    v-for="person in staff"
                    :key="person.id"
                    class="flex flex-wrap items-center gap-4 overflow-visible border-b border-b-rule px-2 py-4 transition duration-fast ease-product hover:bg-paper-sunk"
                    :class="person.is_active ? '' : 'opacity-60'"
                >
                    <span
                        class="numeral hidden size-10 shrink-0 items-center justify-center rounded bg-pill-neutral text-14 text-ink-2 sm:flex"
                        aria-hidden="true"
                    >
                        {{ person.initial }}
                    </span>

                    <div class="min-w-0 flex-1 sm:flex-none sm:basis-48">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-block size-2 shrink-0 rounded"
                                :class="person.is_active ? 'bg-green' : 'bg-ink-4'"
                                aria-hidden="true"
                            />
                            <span class="text-14 font-medium text-ink">{{ person.name }}</span>
                            <Badge variant="solid" tone="neutral">{{ person.role_label }}</Badge>
                        </div>
                        <p
                            v-if="!hasSchedule(person)"
                            class="mt-1 text-13 text-ink-2 italic"
                        >
                            {{ person.hours }}
                        </p>
                        <p
                            v-else-if="scheduleVaries(person)"
                            class="mt-1 flex flex-wrap items-center gap-2"
                        >
                            <Badge variant="solid" tone="accent">Varies daily</Badge>
                            <span class="text-13 text-ink-2">{{ daysWorked(person) }} days/wk</span>
                        </p>
                        <p
                            v-else
                            class="mt-1 text-13 text-ink-2"
                        >
                            {{ person.hours }}
                        </p>
                    </div>

                    <div class="flex w-full min-w-0 flex-1 items-end justify-between gap-2 overflow-visible sm:w-auto">
                        <button
                            v-for="(label, index) in WEEK_DAYS"
                            :key="`${person.id}-${index}`"
                            type="button"
                            class="group relative flex min-h-tap w-8 flex-col items-center justify-end overflow-visible"
                            :aria-label="`${WEEK_DAY_NAMES[index]}, ${dayTip(person, person.daily_hours[index] ?? 0)}`"
                            @mouseenter="hoveredBar = barKey(person.id, index)"
                            @mouseleave="hoveredBar = null"
                            @focus="hoveredBar = barKey(person.id, index)"
                            @blur="hoveredBar = null"
                        >
                            <span
                                class="pointer-events-none absolute inset-x-0 bottom-full z-10 mb-1 flex justify-center transition duration-fast ease-product"
                                :class="hoveredBar === barKey(person.id, index) ? 'opacity-100' : 'opacity-0'"
                                aria-hidden="true"
                            >
                                <span class="whitespace-nowrap rounded bg-ink px-2 py-1 text-12 text-paper numeral">
                                    {{ dayTip(person, person.daily_hours[index] ?? 0) }}
                                </span>
                            </span>
                            <span class="mb-1.5 text-12 text-ink-2">{{ label }}</span>
                            <span class="flex h-row w-full flex-col justify-end overflow-hidden rounded bg-pill-neutral transition duration-fast ease-product group-hover:bg-ink-tint">
                                <span
                                    v-if="(person.daily_hours[index] ?? 0) > 0"
                                    class="block w-full rounded bg-accent"
                                    :style="{ height: barHeight(person, person.daily_hours[index] ?? 0) }"
                                />
                            </span>
                        </button>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="numeral text-17 text-ink">{{ person.weekly_hours ?? '—' }}</p>
                        <p class="mt-0.5 text-12 text-ink-2">{{ load(person.booked_this_week) }}</p>
                    </div>

                    <div class="flex w-full shrink-0 items-center justify-end gap-1 sm:w-auto">
                        <Button variant="ghost" @click="openHours(person)">Hours</Button>
                        <Button variant="ghost" @click="openEdit(person)">Edit</Button>
                        <Menu
                            v-if="canOpenMenu(person)"
                            :label="`More actions for ${person.name}`"
                        >
                            <MenuItem danger @click="deactivate(person)">Deactivate</MenuItem>
                        </Menu>
                        <button
                            v-else
                            type="button"
                            disabled
                            class="inline-flex h-8 w-8 items-center justify-center rounded text-ink-3"
                            :aria-label="
                                person.id === page.props.auth.user?.id
                                    ? 'You cannot deactivate your own account'
                                    : `No more actions for ${person.name}`
                            "
                        >
                            <svg width="14" height="14" viewBox="0 0 14 14" fill="currentColor" aria-hidden="true">
                                <circle cx="7" cy="2.5" r="1.25" />
                                <circle cx="7" cy="7" r="1.25" />
                                <circle cx="7" cy="11.5" r="1.25" />
                            </svg>
                        </button>
                    </div>
                </li>
            </ul>

            <AddCard
                title="Invite a team member"
                description="They get their own diary, hours and time off, and become a column alongside yours."
                @click="openCreate"
            />

            <p class="caption mt-4">
                <span class="numeral">{{ active }}</span> on the team
                <template v-if="active !== staff.length">
                    · <span class="numeral">{{ staff.length - active }}</span> inactive
                </template>
            </p>
        </div>

        </div>

        <SlideOver :show="sheetOpen" :title="editingId ? 'Edit staff' : 'Add staff'" @close="sheetOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <TextInput v-model="form.name" label="Name" :error="form.errors.name" required />
                <TextInput v-model="form.email" type="email" label="Email" :error="form.errors.email" required />
                <StaffColourField v-model="form.colour" :error="form.errors.colour" />
                <Checkbox v-model="form.is_bookable" label="Takes bookings" hint="Appears as a column in the diary." />

                <Checkbox
                    v-if="!editingOwner"
                    v-model="form.can_see_customer_contacts"
                    label="Can see every customer's contact details"
                    hint="Off means they only see subjects booked to them."
                />
                <Checkbox
                    v-if="editingId"
                    v-model="form.is_active"
                    label="Active"
                    hint="Inactive people keep their history but stop appearing anywhere new."
                />

                <fieldset class="space-y-2">
                    <legend class="caption">Services</legend>

                    <Callout v-if="services.length === 0" data-testid="staff-services-empty">
                        There is nothing to assign yet.
                        <template #action>
                            <Link :href="route('services.index')" class="underline decoration-rule underline-offset-4">
                                Add a service
                            </Link>
                        </template>
                    </Callout>

                    <template v-else>
                        <Checkbox
                            v-for="service in services"
                            :key="service.id"
                            :model-value="form.service_ids.includes(service.id)"
                            :label="service.name"
                            @update:model-value="toggleService(service.id, $event)"
                        />

                        <Callout v-if="nothingSelected" tone="danger" data-testid="staff-services-warning">
                            This person won't be bookable online for anything.
                        </Callout>
                    </template>
                </fieldset>
            </form>
            <template #footer>
                <Button :loading="form.processing" @click="submit">Save</Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
