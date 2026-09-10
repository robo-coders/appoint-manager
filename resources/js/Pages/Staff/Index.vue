<script setup lang="ts">
import { DEFAULT_STAFF_COLOUR } from '@/lib/staffColour';
import AppLayout from '@/Layouts/AppLayout.vue';
import AddCard from '@/Components/ui/AddCard.vue';
import Badge from '@/Components/ui/Badge.vue';
import StaffColourField from '@/Components/ui/StaffColourField.vue';
import Button from '@/Components/ui/Button.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import Menu from '@/Components/ui/Menu.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import type { StaffRecord } from '@/types/models';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * The people who work in the business.
 *
 * **Not a table.** It was four columns — name, email, two badges — which is a
 * grid built for comparing down a column, and nobody compares two groomers'
 * "Bookable" flags. What is actually asked of this screen is "who is this, when
 * do they work, how busy are they", and that is a row: the initial, the name
 * and role, the week's hours underneath, the load hard right, and the two things
 * she does to a person at the end of it.
 *
 * The email is gone from the row and stays in the edit sheet. It is how the
 * account signs in, not something anybody reads down a list.
 *
 * The last row adds one — `ui/AddCard`. An invite living in the list rather than
 * only behind the header button is what makes a one-person salon look like a
 * salon that could have two.
 */
const props = defineProps<{
    staff: Array<
        StaffRecord & {
            initial: string;
            role_label: string;
            hours: string;
            weekly_hours: string | null;
            booked_this_week: number;
        }
    >;
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
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.colour = DEFAULT_STAFF_COLOUR;
    form.is_bookable = true;
    form.is_active = true;
    form.can_see_customer_contacts = true;
    sheetOpen.value = true;
};

/*
 * An owner's own row has no contact-visibility control — see the sheet below.
 */
const editingOwner = computed(
    () => props.staff.find((person) => person.id === editingId.value)?.role === 'owner',
);

const openEdit = (person: StaffRecord) => {
    editingId.value = person.id;
    form.name = person.name;
    form.email = person.email;
    form.colour = person.colour ?? DEFAULT_STAFF_COLOUR;
    form.is_bookable = person.is_bookable;
    form.is_active = person.is_active;
    form.can_see_customer_contacts = person.can_see_customer_contacts;
    sheetOpen.value = true;
};

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
        <!-- Capped at `--record`. A staff row is a record, and unbounded it put
             "Hours" and "Edit" a screen away from the name they act on. -->
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
                    <!--
                        The initial, on a wash rather than filled with ink. A
                        black square per row is the weight of a primary button
                        spent on a decoration, and eight of them down a list is
                        eight full-contrast marks competing with the eight names
                        beside them. `colour` is per-user data and stays a 6px
                        square by the name — it identifies a column in the diary,
                        and a mark filled with it would put six unrelated hues
                        down one list.
                    -->
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
                            <!-- The role is a description, not a state, so it
                                 wears the quietest pill there is. An inactive
                                 person is already said by the row's own opacity
                                 and does not need a second, contradictory
                                 reading of the same fact in the badge. -->
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
                        <!--
                            The menu, or the space where it would be. She has no
                            "Deactivate" on her own row and neither does an
                            already-inactive person, and without the placeholder
                            those rows pull Hours and Edit 32px right of every
                            other row's — a ragged right edge down the one column
                            the eye uses to find a control.
                        -->
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
                <!--
                    The one colour the operator app lets anybody choose. It is
                    per-user *data*, not a token, and it is spent on a 6px square
                    beside a name — never on a diary block, which is what made
                    the old diary read as a spreadsheet.
                -->
                <StaffColourField v-model="form.colour" :error="form.errors.colour" />
                <Checkbox v-model="form.is_bookable" label="Takes bookings" hint="Appears as a column in the diary." />

                <!--
                    The wording is the wording the onboarding step used when it
                    first asked. A permission described one way while it is being
                    granted and another way afterwards is a permission nobody is
                    sure they set — so both screens say the same sentence, and
                    the hint states what "off" does rather than leaving it to be
                    inferred from the absence of "on".

                    Not offered on an owner: `ContactVisibility` reads `isOwner()`
                    before it reads the column, so the control would be a switch
                    that visibly does nothing.
                -->
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
            </form>
            <template #footer>
                <Button :loading="form.processing" @click="submit">Save</Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
