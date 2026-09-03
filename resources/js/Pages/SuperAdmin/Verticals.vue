<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { Head, useForm } from '@inertiajs/vue3';

/**
 * Business types tenants pick at signup.
 *
 * The one idea: **a new vertical is a form submit, not a deploy.** The old
 * source was `config/verticals.php`, which only ever held groomer, so adding
 * barber meant shipping PHP. This screen writes a row; registration reads it.
 *
 * Create and list only. A key cannot be changed later — tenants store it as
 * `type` — so the hint says so before anyone types one they will regret.
 */
defineProps<{
    verticals: Array<{ key: string; label: string }>;
}>();

const form = useForm({
    key: '',
    label: '',
    subject_singular: '',
    subject_plural: '',
});

const submit = () =>
    form.post(route('super-admin.verticals.store'), {
        onSuccess: () => form.reset(),
    });

const columns: Column[] = [
    { key: 'key', label: 'Key', width: 'staff', narrow: 'meta' },
    { key: 'label', label: 'Label', narrow: 'title' },
];
</script>

<template>
    <AppLayout>
        <Head title="Verticals" />

        <PageHeader
            title="Verticals"
            description="Business types tenants pick when they sign up. A new one is live on the register form as soon as it is created."
        />

        <form class="max-w-measure space-y-4" @submit.prevent="submit">
            <TextInput
                v-model="form.key"
                label="Key"
                hint="Lowercase, no spaces. Can't be changed later."
                :error="form.errors.key"
                mono
                required
                autocomplete="off"
            />
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
            <div class="pt-2">
                <Button type="submit" :loading="form.processing">Create vertical</Button>
            </div>
        </form>

        <section class="mt-12">
            <h2 class="mb-2 text-15">Existing</h2>
            <Table
                :columns="columns"
                :rows="verticals"
                row-key="key"
                label="Existing verticals"
                empty-title="No verticals yet"
                empty-description="Create one above. Tenants pick it when they sign up."
            >
                <template #cell:key="{ row }">
                    <span class="font-mono text-12 text-ink">{{ row.key }}</span>
                </template>
            </Table>
        </section>
    </AppLayout>
</template>
