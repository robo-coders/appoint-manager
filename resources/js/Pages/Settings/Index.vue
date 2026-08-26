<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import Combobox from '@/Components/ui/Combobox.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SaveState from '@/Components/ui/SaveState.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Business details.
 *
 * What this was: nine hand-rolled inputs with a hand-written label above each,
 * no `form.errors` binding anywhere, and a 400-option native select for the
 * timezone. The consequence of the missing error binding was not cosmetic — a
 * rejected value came back with *nothing* on screen to say so, so the form
 * silently discarded whatever had been typed.
 *
 * What it is now:
 *
 *   - every field is a library control, and every one of them takes its own
 *     error, which `ui/Field` renders below the input and links with
 *     `aria-describedby`
 *   - the timezone is `ui/Combobox` — typing "lon" finds Europe/London, which
 *     is not something a native select with four hundred options can do
 *   - `ui/SaveState` says whether there is anything unsaved, whether it is
 *     saving, and whether it saved. A form that succeeds silently is a form
 *     people press twice
 *   - Branding and Payments are tabs on this screen rather than two underlined
 *     words above it
 */
const props = defineProps<{
    business: {
        name: string;
        timezone: string;
        email: string | null;
        phone: string | null;
        address_line_1: string | null;
        address_line_2: string | null;
        city: string | null;
        postcode: string | null;
    };
    timezones: string[];
}>();

const form = useForm({
    name: props.business.name,
    timezone: props.business.timezone,
    email: props.business.email ?? '',
    phone: props.business.phone ?? '',
    address_line_1: props.business.address_line_1 ?? '',
    address_line_2: props.business.address_line_2 ?? '',
    city: props.business.city ?? '',
    postcode: props.business.postcode ?? '',
});

const savedAt = ref<number | null>(null);

/*
 * "Europe/London" reads better as "Europe · London", and the underscore in
 * "New_York" is a filename, not a place. The value on the wire is untouched.
 */
const timezoneOptions = computed(() =>
    props.timezones.map((zone) => ({ value: zone, label: zone.replace(/_/g, ' ').replace('/', ' · ') })),
);

const submit = () =>
    form.patch(route('settings.update'), {
        preserveScroll: true,
        onSuccess: () => (savedAt.value = Date.now()),
    });
</script>

<template>
    <AppLayout>
        <Head title="Settings" />
        <PageHeader title="Settings" description="Business details, branding and payments." />

        <SettingsNav current="business" />

        <form class="mt-6 max-w-measure space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.name"
                label="Business name"
                hint="What customers see on the booking page and in every message."
                :error="form.errors.name"
                required
            />

            <!--
                A searchable combobox, not four hundred options in a native
                select. "lon" reaches Europe/London; scrolling reaches it in
                about a minute.
            -->
            <Combobox
                v-model="form.timezone"
                label="Timezone"
                :options="timezoneOptions"
                hint="Every time in the app and on the booking page is shown in this zone. Bookings are stored in UTC."
                :error="form.errors.timezone"
                required
            />

            <TextInput
                v-model="form.email"
                type="email"
                label="Email"
                autocomplete="email"
                hint="Where booking notifications go."
                :error="form.errors.email"
            />
            <TextInput
                v-model="form.phone"
                type="tel"
                label="Phone"
                autocomplete="tel"
                hint="Shown to customers who need to reach you about an appointment."
                :error="form.errors.phone"
            />

            <TextInput v-model="form.address_line_1" label="Address" :error="form.errors.address_line_1" />
            <TextInput
                v-model="form.address_line_2"
                label="Address line 2"
                :error="form.errors.address_line_2"
            />

            <div class="grid gap-4 md:grid-cols-2">
                <TextInput v-model="form.city" label="Town or city" :error="form.errors.city" />
                <TextInput v-model="form.postcode" label="Postcode" mono :error="form.errors.postcode" />
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button type="submit" :loading="form.processing">Save</Button>
                <SaveState :dirty="form.isDirty" :processing="form.processing" :saved-at="savedAt" />
            </div>
        </form>
    </AppLayout>
</template>
