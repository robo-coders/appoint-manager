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
</script>

<template>
    <AppLayout>
        <Head title="Staff" />
        <div class="max-w-record">
        <PageHeader title="Staff" description="People who work in the business.">
            <Button @click="openCreate">Add staff</Button>
        </PageHeader>

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
                    class="flex flex-wrap items-center gap-4 border-b border-b-rule px-2 py-4 transition duration-fast ease-product hover:bg-paper-sunk"
                    :class="person.is_active ? '' : 'opacity-60'"
                >
                    <span
                        class="numeral hidden size-10 shrink-0 items-center justify-center rounded bg-pill-neutral text-14 text-ink-2 sm:flex"
                        aria-hidden="true"
                    >
                        {{ person.initial }}
                    </span>

                    <div class="min-w-0 flex-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <span
                                class="inline-block size-2 shrink-0 rounded"
                                :style="{ backgroundColor: person.colour ?? DEFAULT_STAFF_COLOUR }"
                                aria-hidden="true"
                            />
                            <span class="text-14 font-medium text-ink">{{ person.name }}</span>
                            <Badge variant="solid" tone="neutral">{{ person.role_label }}</Badge>
                        </div>
                        <p class="mt-1 text-13 text-ink-2">{{ person.hours }}</p>
                    </div>

                    <div class="shrink-0 text-right">
                        <p class="numeral text-13 text-ink">{{ person.weekly_hours ?? '—' }}</p>
                        <p class="mt-0.5 text-12 text-ink-2">{{ load(person.booked_this_week) }}</p>
                    </div>

                    <div class="flex w-full shrink-0 items-center justify-end gap-1 sm:w-auto">
                        <Button variant="ghost" @click="openHours(person)">Hours</Button>
                        <Button variant="ghost" @click="openEdit(person)">Edit</Button>
                        <Menu
                            v-if="person.is_active && person.id !== page.props.auth.user?.id"
                            :label="`More actions for ${person.name}`"
                        >
                            <MenuItem danger @click="deactivate(person)">Deactivate</MenuItem>
                        </Menu>
                        <span v-else class="size-8" aria-hidden="true" />
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
