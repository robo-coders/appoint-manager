<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { toast } from '@/lib/toast';
import { Head, router } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    staff: Array<{ id: number; name: string; is_active: boolean; url: string }>;
}>();

const copy = async (url: string) => {
    try {
        await navigator.clipboard.writeText(url);
        toast.success('Link copied');
    } catch {
        toast.error('Could not copy. The address is on screen — read it from there.');
    }
};

const regenerate = (id: number) =>
    router.post(route('settings.calendar.regenerate', id), {}, { preserveScroll: true });

const columns: Column[] = [
    { key: 'name', label: 'Name', narrow: 'title' },
    { key: 'state', label: 'Status', width: 'status', narrow: 'line' },
    { key: 'url', label: 'Link' },
];

const rows = computed(() =>
    props.staff.map((person) => ({ ...person, state: person.is_active ? 'Active' : 'Inactive' })),
);
</script>

<template>
    <AppLayout>
        <Head title="Calendar sync" />
        <PageHeader
            title="Settings"
            description="Business details, branding, loyalty, calendars and payments."
        />

        <SettingsNav current="calendar" />

        <section class="mt-6">
            <h2 class="text-15">Calendar sync</h2>
            <p class="caption mt-1 max-w-measure">
                Each person has their own link. Copy it and send it to them — confirmed appointments then appear in
                their own calendar app and keep themselves up to date.
            </p>

            <Table
                class="mt-4"
                :columns="columns"
                :rows="rows"
                label="Calendar links, one per member of staff"
                :row-label="(row) => `Actions for ${row.name}`"
                empty-title="No staff yet"
                empty-description="Add somebody on the staff screen and their calendar link appears here."
            >
                <template #cell:state="{ row }">
                    <Badge :tone="row.is_active ? 'confirmed' : 'neutral'">{{ row.state }}</Badge>
                </template>

                <template #cell:url="{ row }">
                    <div class="flex flex-wrap items-center gap-3">
                        <Button variant="secondary" @click="copy(String(row.url))">Copy link</Button>
                        <span class="break-all font-mono text-12 text-ink-2">{{ row.url }}</span>
                    </div>
                </template>

                <template #actions="{ row }">
                    <MenuItem danger @click="regenerate(Number(row.id))">Replace the link</MenuItem>
                </template>
            </Table>

            <div class="mt-4 max-w-measure space-y-1 text-13 text-ink-2">
                <p>Paste this link into your calendar app to see your bookings automatically.</p>
                <p>iPhone: Settings → Calendar → Accounts → Add Account → Other → Add Subscribed Calendar.</p>
                <p>Google Calendar: Settings → Add calendar → From URL.</p>
            </div>

            <p class="caption mt-4 max-w-measure">
                Replacing a link stops the old one working straight away. Send the new one, or that person's calendar
                quietly goes empty.
            </p>
        </section>
    </AppLayout>
</template>
