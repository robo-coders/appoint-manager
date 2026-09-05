<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Checkbox from '@/Components/ui/Checkbox.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { penceToPoundsInput, poundsInputToPence } from '@/lib/money';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Business types tenants pick at signup.
 *
 * The one idea: **a new vertical is a form submit, not a deploy.** The old
 * source was `config/verticals.php`, which only ever held groomer, so adding
 * barber meant shipping PHP. This screen writes a row; registration reads it.
 *
 * What changed here:
 *
 *   - **The two JSON columns are on the form.** They were not, and
 *     `VerticalController::store` wrote `[]` into both regardless — so a
 *     vertical created here had no subject fields (the booking page asked the
 *     customer nothing about what they were bringing) and no default services
 *     (onboarding pre-filled an empty price list). Those two lists *are* the
 *     trade; a vertical without them is a word in a dropdown.
 *
 *   - **Edit and delete.** One sheet serves both, pre-filled from the row, so
 *     there is one form to keep correct rather than two that drift. A key
 *     cannot be changed — tenants store it as `type` — so in edit mode it is
 *     shown as text rather than as a field that looks editable and is not.
 *
 *   - **Delete is soft-blocked while a tenant is on the key.** `tenants.type`
 *     is a plain string with no foreign key, so the delete would succeed and
 *     take effect as a silent change to a live salon: every tenant on that key
 *     falls back to the groomer definition. The count is on screen and the
 *     dialog refuses rather than warning.
 *
 * Choices for a `select` subject field are one per line in a text field rather
 * than a repeater inside a repeater. Two levels of add/remove for a list of
 * words like "small, medium, large" is more control than the data deserves.
 */
type SubjectField = {
    key: string;
    label: string;
    type: 'text' | 'textarea' | 'select';
    required: boolean;
    options?: string[];
};

type DefaultService = {
    name: string;
    duration_minutes: number;
    price: number;
    deposit_amount: number;
    rebook_interval?: { value: number; unit: 'days' | 'weeks' | 'months' } | null;
};

type VerticalRow = {
    id: number;
    key: string;
    label: string;
    subject_singular: string;
    subject_plural: string;
    customer_singular: string;
    appointment_singular: string;
    subject_fields: SubjectField[];
    default_services: DefaultService[];
    tenants_count: number;
};

const props = defineProps<{ verticals: VerticalRow[] }>();

/*
 * The sheet's own state, mirroring `Services/Index.vue`: null id is create,
 * a number is edit.
 */
const sheetOpen = ref(false);
const editing = ref<VerticalRow | null>(null);
const deleting = ref<VerticalRow | null>(null);

/**
 * The form's rows carry their money as pounds strings and their choices as one
 * line each, because that is what a person types. `submit` converts both back
 * to the shapes the consumers read — integer pence and a list of strings —
 * exactly as the services sheet does.
 */
type FieldRow = { key: string; label: string; type: string; required: boolean; options: string };
type ServiceRow = {
    name: string;
    duration_minutes: number;
    price: string;
    deposit: string;
    interval: string;
    unit: string;
};

type VerticalForm = {
    key: string;
    label: string;
    subject_singular: string;
    subject_plural: string;
    customer_singular: string;
    appointment_singular: string;
    fields: FieldRow[];
    services: ServiceRow[];
};

const form = useForm<VerticalForm>({
    key: '',
    label: '',
    subject_singular: '',
    subject_plural: '',
    customer_singular: 'client',
    appointment_singular: 'appointment',
    fields: [] as FieldRow[],
    services: [] as ServiceRow[],
});

const fieldTypes = [
    { value: 'text', label: 'One line of text' },
    { value: 'textarea', label: 'A paragraph' },
    { value: 'select', label: 'A list of choices' },
];

const intervalUnits = [
    { value: 'weeks', label: 'weeks' },
    { value: 'days', label: 'days' },
    { value: 'months', label: 'months' },
];

const blankField = (): FieldRow => ({ key: '', label: '', type: 'text', required: false, options: '' });
const blankService = (): ServiceRow => ({
    name: '',
    duration_minutes: 60,
    price: '0.00',
    deposit: '0.00',
    interval: '',
    unit: 'weeks',
});

const openCreate = () => {
    editing.value = null;
    form.reset();
    form.clearErrors();
    form.fields = [];
    form.services = [];
    sheetOpen.value = true;
};

const openEdit = (vertical: VerticalRow) => {
    editing.value = vertical;
    form.clearErrors();
    form.key = vertical.key;
    form.label = vertical.label;
    form.subject_singular = vertical.subject_singular;
    form.subject_plural = vertical.subject_plural;
    form.customer_singular = vertical.customer_singular;
    form.appointment_singular = vertical.appointment_singular;
    form.fields = vertical.subject_fields.map((field) => ({
        key: field.key,
        label: field.label,
        type: field.type,
        required: field.required,
        options: (field.options ?? []).join('\n'),
    }));
    form.services = vertical.default_services.map((service) => ({
        name: service.name,
        duration_minutes: service.duration_minutes,
        price: penceToPoundsInput(service.price),
        deposit: penceToPoundsInput(service.deposit_amount),
        interval: service.rebook_interval ? String(service.rebook_interval.value) : '',
        unit: service.rebook_interval?.unit ?? 'weeks',
    }));
    sheetOpen.value = true;
};

/*
 * The wire shape. `transform` rather than mutating the form fields, because the
 * inputs are bound to the human shape and rewriting them under the person's
 * cursor on every submit is how a failed save loses what they typed.
 *
 * `key` is dropped on edit: the request rule for it is `prohibited`, so sending
 * one is a validation failure rather than a silently ignored value.
 */
const payload = (data: VerticalForm) => {
    const body = {
        key: data.key,
        label: data.label,
        subject_singular: data.subject_singular,
        subject_plural: data.subject_plural,
        customer_singular: data.customer_singular,
        appointment_singular: data.appointment_singular,
        subject_fields: data.fields.map((field) => ({
            key: field.key,
            label: field.label,
            type: field.type,
            required: field.required,
            options:
                field.type === 'select'
                    ? field.options
                          .split(/[\n,]/)
                          .map((option) => option.trim())
                          .filter((option) => option !== '')
                    : [],
        })),
        default_services: data.services.map((service) => ({
            name: service.name,
            duration_minutes: Number(service.duration_minutes),
            price: poundsInputToPence(service.price),
            deposit_amount: poundsInputToPence(service.deposit),
            rebook_interval:
                service.interval.trim() === ''
                    ? null
                    : { value: Number(service.interval), unit: service.unit },
        })),
    } as Record<string, unknown>;

    if (editing.value) {
        delete body.key;
    }

    return body;
};

const submit = () => {
    const request = form.transform(payload);

    if (editing.value) {
        request.patch(route('super-admin.verticals.update', editing.value.id), {
            preserveScroll: true,
            onSuccess: () => (sheetOpen.value = false),
        });

        return;
    }

    request.post(route('super-admin.verticals.store'), {
        preserveScroll: true,
        onSuccess: () => (sheetOpen.value = false),
    });
};

const confirmDelete = () => {
    if (!deleting.value) return;

    router.delete(route('super-admin.verticals.destroy', deleting.value.id), {
        preserveScroll: true,
        onFinish: () => (deleting.value = null),
    });
};

/*
 * The error keys the server sends back are the wire shape's
 * (`subject_fields.0.key`), not the form's (`fields[0].key`), so a row's field
 * reads its own error by index. `form.errors` is a flat record; the index is
 * the only thing that links the two.
 */
const errors = computed(() => form.errors as unknown as Record<string, string | undefined>);

const fieldError = (index: number, key: string) => errors.value[`subject_fields.${index}.${key}`];
const serviceError = (index: number, key: string) => errors.value[`default_services.${index}.${key}`];

const columns: Column[] = [
    { key: 'key', label: 'Key', width: 'staff', narrow: 'meta' },
    { key: 'label', label: 'Label', narrow: 'title' },
    { key: 'subject', label: 'Subject', width: 'staff', secondary: true },
    { key: 'fields_count', label: 'Fields', width: 'time', align: 'right', numeric: true, secondary: true },
    { key: 'services_count', label: 'Services', width: 'time', align: 'right', numeric: true, secondary: true },
    { key: 'tenants_count', label: 'In use', width: 'time', align: 'right', numeric: true, narrow: 'line' },
];

const rows = computed(() =>
    props.verticals.map((vertical) => ({
        ...vertical,
        subject: vertical.subject_singular,
        fields_count: vertical.subject_fields.length,
        services_count: vertical.default_services.length,
    })),
);
</script>

<template>
    <AppLayout>
        <Head title="Verticals" />

        <PageHeader
            title="Verticals"
            description="Business types tenants pick when they sign up. A new one is live on the register form as soon as it is created."
        >
            <Button @click="openCreate">Add vertical</Button>
        </PageHeader>

        <Table
            :columns="columns"
            :rows="rows"
            row-key="key"
            label="Verticals, by label"
            :row-label="(row) => `Actions for ${row.label}`"
            empty-title="No verticals yet"
            empty-description="Create one and it appears on the register form. Its subject fields and default services are what make it a trade rather than a word in a list."
        >
            <template #cell:key="{ row }">
                <span class="font-mono text-12 text-ink">{{ row.key }}</span>
            </template>

            <template #cell:tenants_count="{ row }">
                <span :class="row.tenants_count ? '' : 'text-ink-2'">{{ row.tenants_count }}</span>
            </template>

            <template #actions="{ row }">
                <MenuItem @click="openEdit(row as unknown as VerticalRow)">Edit</MenuItem>
                <MenuItem danger @click="deleting = row as unknown as VerticalRow">Delete</MenuItem>
            </template>

            <template #footer>
                <span class="numeral">{{ rows.length }}</span> vertical{{ rows.length === 1 ? '' : 's' }}
            </template>

            <template #empty-action>
                <Button variant="ghost" @click="openCreate">Add the first one</Button>
            </template>
        </Table>

        <SlideOver
            :show="sheetOpen"
            :title="editing ? `Edit ${editing.label}` : 'Add vertical'"
            @close="sheetOpen = false"
        >
            <form class="space-y-4" @submit.prevent="submit">
                <!--
                    The key, once, and then never again. In edit mode it is the
                    row's own key set as type rather than a disabled input:
                    a control you cannot use is still a control, and this is a
                    fact about the record.
                -->
                <TextInput
                    v-if="!editing"
                    v-model="form.key"
                    label="Key"
                    hint="Lowercase, no spaces. Can't be changed later."
                    :error="form.errors.key"
                    mono
                    required
                    autocomplete="off"
                />
                <div v-else class="space-y-1">
                    <p class="caption">Key</p>
                    <p class="font-mono text-13 text-ink">{{ editing.key }}</p>
                </div>

                <TextInput
                    v-model="form.label"
                    label="Display label"
                    hint="What the person signing up sees in the list."
                    :error="form.errors.label"
                    required
                />

                <div class="grid gap-4 md:grid-cols-2">
                    <TextInput
                        v-model="form.subject_singular"
                        label="Subject (singular)"
                        hint="e.g. client, dog."
                        :error="form.errors.subject_singular"
                        required
                    />
                    <TextInput
                        v-model="form.subject_plural"
                        label="Subject (plural)"
                        hint="e.g. clients, dogs."
                        :error="form.errors.subject_plural"
                        required
                    />
                </div>

                <div class="grid gap-4 md:grid-cols-2">
                    <TextInput
                        v-model="form.customer_singular"
                        label="Customer word"
                        hint="What the salon calls the person paying."
                        :error="form.errors.customer_singular"
                        required
                    />
                    <TextInput
                        v-model="form.appointment_singular"
                        label="Appointment word"
                        hint="e.g. appointment, groom, cut."
                        :error="form.errors.appointment_singular"
                        required
                    />
                </div>

                <!--
                    What the booking page asks about the subject. Read by
                    `StorePublicBookingRequest`, `StoreManualBookingRequest` and
                    `Public/BookingIsland.vue`.
                -->
                <section class="space-y-3 pt-2">
                    <div>
                        <h3 class="text-15">Subject fields</h3>
                        <p class="caption mt-1">
                            What a customer is asked about the {{ form.subject_singular || 'subject' }} when they
                            book. Leave empty and the booking page asks for a name only.
                        </p>
                    </div>

                    <Callout v-if="errors['subject_fields']" tone="danger">
                        {{ errors['subject_fields'] }}
                    </Callout>

                    <div
                        v-for="(field, index) in form.fields"
                        :key="`field-${index}`"
                        class="space-y-3 border-b border-b-rule pb-4"
                    >
                        <div class="grid gap-3 md:grid-cols-2">
                            <TextInput
                                v-model="field.key"
                                label="Key"
                                mono
                                :error="fieldError(index, 'key')"
                                required
                            />
                            <TextInput
                                v-model="field.label"
                                label="Label"
                                :error="fieldError(index, 'label')"
                                required
                            />
                        </div>
                        <Select
                            v-model="field.type"
                            label="Answer"
                            :options="fieldTypes"
                            :error="fieldError(index, 'type')"
                            required
                        />
                        <TextInput
                            v-if="field.type === 'select'"
                            v-model="field.options"
                            label="Choices"
                            hint="Separate them with commas or new lines."
                            :error="fieldError(index, 'options')"
                        />
                        <Checkbox v-model="field.required" label="The customer must answer this" />
                        <Button variant="ghost" @click="form.fields.splice(index, 1)">Remove field</Button>
                    </div>

                    <Button variant="secondary" @click="form.fields.push(blankField())">Add field</Button>
                </section>

                <!--
                    The price list a new salon starts from. Read by
                    `OnboardingController` to pre-fill the services step, and by
                    `VerticalInterval` for the rebooking rhythm per service.
                -->
                <section class="space-y-3 pt-2">
                    <div>
                        <h3 class="text-15">Default services</h3>
                        <p class="caption mt-1">
                            The price list onboarding starts a new salon from. They can change every line of it.
                        </p>
                    </div>

                    <Callout v-if="errors['default_services']" tone="danger">
                        {{ errors['default_services'] }}
                    </Callout>

                    <div
                        v-for="(service, index) in form.services"
                        :key="`service-${index}`"
                        class="space-y-3 border-b border-b-rule pb-4"
                    >
                        <TextInput
                            v-model="service.name"
                            label="Name"
                            :error="serviceError(index, 'name')"
                            required
                        />
                        <div class="grid grid-cols-2 gap-3">
                            <TextInput
                                v-model.number="service.duration_minutes"
                                type="number"
                                label="Duration"
                                suffix="min"
                                :error="serviceError(index, 'duration_minutes')"
                                required
                            />
                            <TextInput
                                v-model="service.price"
                                label="Price"
                                prefix="£"
                                mono
                                :error="serviceError(index, 'price')"
                                required
                            />
                        </div>
                        <div class="grid grid-cols-2 gap-3">
                            <TextInput
                                v-model="service.deposit"
                                label="Deposit"
                                prefix="£"
                                mono
                                hint="Zero means pay on the day."
                                :error="serviceError(index, 'deposit_amount')"
                            />
                            <TextInput
                                v-model="service.interval"
                                type="number"
                                label="Due again after"
                                hint="Blank means no suggested rhythm."
                                :error="serviceError(index, 'rebook_interval.value')"
                            />
                        </div>
                        <Select
                            v-model="service.unit"
                            label="Counted in"
                            :options="intervalUnits"
                            :error="serviceError(index, 'rebook_interval.unit')"
                        />
                        <Button variant="ghost" @click="form.services.splice(index, 1)">Remove service</Button>
                    </div>

                    <Button variant="secondary" @click="form.services.push(blankService())">Add service</Button>
                </section>
            </form>

            <template #footer>
                <Button :loading="form.processing" @click="submit">
                    {{ editing ? 'Save changes' : 'Create vertical' }}
                </Button>
            </template>
        </SlideOver>

        <!--
            Two dialogs' worth of copy in one, because the answer depends on the
            count and a person deleting a vertical needs to be told which case
            they are in *before* they press the button, not after.
        -->
        <ConfirmDialog
            :show="deleting !== null"
            :title="
                deleting && deleting.tenants_count > 0
                    ? `${deleting.label} is in use`
                    : `Delete ${deleting?.label ?? 'this vertical'}?`
            "
            :confirm-label="deleting && deleting.tenants_count > 0 ? 'Delete anyway' : 'Delete'"
            @close="deleting = null"
            @confirm="confirmDelete"
        >
            <template v-if="deleting && deleting.tenants_count > 0">
                <span class="numeral">{{ deleting.tenants_count }}</span>
                {{ deleting.tenants_count === 1 ? 'salon is' : 'salons are' }} set up as
                {{ deleting.label }}. Deleting it would leave
                {{ deleting.tenants_count === 1 ? 'that salon' : 'those salons' }} on the fallback definition — the
                wrong words for their trade, on their own booking page. This will be refused; move them to another
                business type first.
            </template>
            <template v-else>
                Nobody is set up as {{ deleting?.label }}, so nothing live changes. It comes off the register form.
            </template>
        </ConfirmDialog>
    </AppLayout>
</template>
