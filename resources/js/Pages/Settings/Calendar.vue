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

/**
 * Calendar sync. One link per member of staff, for the owner to hand out.
 *
 * **This is entirely owner-facing.** There is no staff login in this product, so
 * there is nobody to show a "your calendar" page to — she copies each person's
 * link and sends it to them however she already talks to them. No part of this
 * is customer-facing and no route makes it so.
 *
 * The two things the screen has to get right:
 *
 *   - **The link is one click away and readable.** It is a URL somebody is about
 *     to paste into another person's phone, so Copy link is the primary action
 *     and the address itself is on screen in mono so it can be read out if the
 *     clipboard is not available.
 *   - **It says what to do with it.** A URL with no instructions is a URL that
 *     comes back as a question, and the answer is four taps deep in two
 *     different settings apps. Both are named below the list.
 *
 * Regenerating is in the row's menu rather than beside Copy link, because it is
 * the destructive one: it silently empties the calendar on the phone of whoever
 * had the old address.
 */
const props = defineProps<{
    staff: Array<{ id: number; name: string; is_active: boolean; url: string }>;
}>();

const copy = async (url: string) => {
    try {
        await navigator.clipboard.writeText(url);
        toast('Link copied.');
    } catch {
        toast('Could not copy. The address is on screen — read it from there.', { tone: 'danger' });
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

                <!--
                    The address on screen as well as on the clipboard. A
                    clipboard write can fail — an insecure origin, a browser that
                    refuses without a user gesture it recognises — and when it
                    does the only recovery is reading the link.
                -->
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

            <!--
                The instructions, once, under the list. Both platforms, because
                asking which phone somebody has before telling them is a
                conversation, and this is a line she forwards.
            -->
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
