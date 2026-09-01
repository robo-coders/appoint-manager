<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import MenuItem from '@/Components/ui/MenuItem.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import PhoneLink from '@/Components/ui/PhoneLink.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Who is due back, and what that is worth.
 *
 * The list is useful even if nobody ever turns messages on: a groomer who
 * rings her regulars needs the phone number, the due date, and a way to say
 * she called. Automatic sending is off until she reviews a dry run.
 */
type Row = {
    subject_id: number;
    subject_name: string;
    customer_name: string | null;
    phone: string | null;
    service_name: string;
    due_on: string;
    due_label: string;
    interval_label: string;
    days_overdue: number;
    price: string;
    opted_out: boolean;
    number_failing: boolean;
};

type DryRunMessage = {
    subject_id: number;
    subject_name: string;
    customer_name: string | null;
    phone: string | null;
    body: string;
    segments: number;
    encoding: string;
    characters: number;
};

const props = defineProps<{
    summary: { count: number; value: string; noun: string };
    rows: Row[];
    stopped: Array<{ subject_id: number; subject_name: string; customer_name: string | null; stopped_on: string | null }>;
    snoozed: Array<{ subject_id: number; subject_name: string; customer_name: string | null; snoozed_until: string | null }>;
    messages_enabled: boolean;
    dry_run: {
        count: number;
        segments: number;
        over_one_segment: number;
        window: string;
        in_window: boolean;
        messages: DryRunMessage[];
        suppressed: Array<DryRunMessage & { reason: string }>;
    } | null;
    window: string;
    timezone: string;
    recent_sends: Array<{
        id: number;
        to: string;
        customer_name: string | null;
        sent_on: string | null;
        status: string;
        failed: boolean;
        segments: number;
        error: string | null;
    }>;
    noun: string;
    noun_plural: string;
}>();

const columns: Column[] = [
    { key: 'subject_name', label: 'Name', sortable: true, narrow: 'title' },
    { key: 'customer_name', label: 'Client', sortable: true, narrow: 'line' },
    { key: 'phone', label: 'Phone', narrow: 'meta' },
    { key: 'due', label: 'Due', sortable: true, width: 'when' },
    { key: 'price', label: 'Usual', align: 'right', numeric: true, width: 'amount' },
];

/**
 * Why a due subject would not be texted, in the operator's language rather than
 * the enum's. Every one of these leaves them on the list: the phone still works.
 */
const suppressionLabels: Record<string, string> = {
    opted_out: 'replied STOP — ring instead',
    no_phone: 'no phone number on file',
    attempts_used: 'already asked twice this time',
    awaiting_follow_up: 'follow-up not due yet',
    number_failing: 'number keeps failing — check it',
};

const failedSends = computed(() => props.recent_sends.filter((send) => send.failed));

const tableRows = computed(() =>
    props.rows.map((row) => ({
        ...row,
        id: row.subject_id,
        due: `${row.due_label} · ${row.interval_label}`,
    })),
);

const confirmingEnable = ref(false);
const preview = useForm({});
const enable = useForm({});
const disable = useForm({});

const startPreview = () => preview.post(route('overdue.preview-enable'));
const confirmEnable = () => {
    confirmingEnable.value = false;
    enable.post(route('overdue.enable'));
};
const call = (phone: string) => {
    window.location.assign(`tel:${phone}`);
};
</script>

<template>
    <AppLayout>
        <Head title="Overdue" />
        <PageHeader
            :title="`Overdue: ${summary.count} ${summary.noun}, ${summary.value}`"
            :description="`Who has gone past their usual interval. The date is the one to check; the interval is how it was worked out.`"
        />

        <Callout v-if="!messages_enabled && !dry_run" class="mb-6">
            Automatic messages are off. The list is yours to ring. Turning messages on starts with a dry run — nothing sends until you confirm.
            <template #action>
                <Button variant="ghost" :loading="preview.processing" @click="startPreview">Preview messages</Button>
            </template>
        </Callout>

        <Callout v-if="dry_run" class="mb-6">
            {{ dry_run.count === 0 ? 'Nobody would be contacted right now.' : `${dry_run.count} message${dry_run.count === 1 ? '' : 's'} would go out.` }}
            Nothing has been sent.
            <template #action>
                <Button @click="confirmingEnable = true">Turn messages on</Button>
            </template>
        </Callout>

        <Callout v-if="dry_run && dry_run.over_one_segment > 0" tone="accent" class="mb-6">
            {{ dry_run.over_one_segment }} of these {{ dry_run.over_one_segment === 1 ? 'is' : 'are' }} longer than one
            text. Networks charge per part, so {{ dry_run.count }}
            {{ dry_run.count === 1 ? 'message' : 'messages' }} would come off your allowance as
            <span class="numeral">{{ dry_run.segments }}</span>. Shortening your salon name in Settings is the quickest way
            down.
        </Callout>

        <Callout v-if="messages_enabled" class="mb-6">
            Automatic messages are on. Due {{ noun_plural }} are contacted once per cycle, between {{ window }}, your time
            ({{ timezone }}).
            <template #action>
                <Button variant="ghost" :loading="disable.processing" @click="disable.post(route('overdue.disable'))">Turn off</Button>
            </template>
        </Callout>

        <ol v-if="dry_run && dry_run.messages.length" class="mb-8 max-w-measure divide-y divide-rule border-y border-rule">
            <li v-for="message in dry_run.messages" :key="message.subject_id" class="py-3">
                <p class="font-medium">{{ message.subject_name }} · {{ message.customer_name }}</p>
                <p class="mt-1 text-13 text-ink-2">{{ message.body }}</p>
                <p class="mt-1 text-13 text-ink-2">
                    <span class="numeral">{{ message.characters }}</span> characters,
                    <span class="numeral">{{ message.segments }}</span>
                    {{ message.segments === 1 ? 'text' : 'texts' }}
                    <template v-if="message.encoding !== 'GSM-7'">
                        — an accent or symbol in this one drops the limit to <span class="numeral">70</span> characters
                    </template>
                </p>
            </li>
        </ol>

        <ol
            v-if="dry_run && dry_run.suppressed.length"
            class="mb-8 max-w-measure divide-y divide-rule border-y border-rule"
        >
            <li v-for="message in dry_run.suppressed" :key="message.subject_id" class="py-3">
                <p>
                    <span class="font-medium">{{ message.subject_name }}</span>
                    <span class="text-ink-2"> · {{ message.customer_name }} · not texted: {{ suppressionLabels[message.reason] ?? message.reason }}</span>
                </p>
            </li>
        </ol>

        <Table
            :columns="columns"
            :rows="tableRows"
            label="Overdue"
            :row-label="(row) => `Actions for ${row.subject_name}`"
            empty-title="Nobody is overdue"
            empty-description="When a regular goes past their interval, they land here."
        >
            <template #cell:phone="{ row }">
                <PhoneLink :phone="row.phone as string | null" />
            </template>
            <template #cell:subject_name="{ row }">
                <span class="font-medium">{{ row.subject_name }}</span>
                <!--
                    Both markers keep the row on the list. Replying STOP stops
                    the texts, not the relationship — she can still ring them,
                    and she needs to know that is now the only way.
                -->
                <span v-if="row.opted_out" class="text-ink-2"> · no texts</span>
                <span v-else-if="row.number_failing" class="text-ink-2"> · check number</span>
            </template>
            <template #cell:due="{ row }">
                <span class="font-medium">Due {{ row.due_label }}</span>
                <span class="text-ink-2"> · {{ row.interval_label }}</span>
            </template>
            <template #cell:price="{ row }">
                <span class="numeral">{{ row.price }}</span>
            </template>
            <template #actions="{ row }">
                <MenuItem v-if="row.phone" @click="call(String(row.phone))">Call</MenuItem>
                <MenuItem @click="router.post(route('overdue.contacted', Number(row.subject_id)))">Mark contacted</MenuItem>
                <MenuItem @click="router.post(route('overdue.snooze', Number(row.subject_id)), { days: 14 })">
                    Snooze two weeks
                </MenuItem>
                <MenuItem @click="router.post(route('overdue.snooze', Number(row.subject_id)), { days: 30 })">
                    Snooze a month
                </MenuItem>
                <MenuItem danger @click="router.post(route('overdue.stop', Number(row.subject_id)))">
                    Stop chasing
                </MenuItem>
            </template>
        </Table>

        <section v-if="snoozed.length" class="mt-8">
            <h2 class="border-b border-b-rule pb-3 text-17">Snoozed</h2>
            <ul class="divide-y divide-rule">
                <li v-for="row in snoozed" :key="row.subject_id" class="flex flex-wrap items-baseline justify-between gap-3 py-3">
                    <p>
                        <span class="font-medium">{{ row.subject_name }}</span>
                        <span class="text-ink-2"> · {{ row.customer_name }}</span>
                        <span v-if="row.snoozed_until" class="text-ink-2"> · until {{ row.snoozed_until }}</span>
                    </p>
                    <Button variant="ghost" @click="router.post(route('overdue.resume', row.subject_id))">Start chasing again</Button>
                </li>
            </ul>
        </section>

        <section v-if="stopped.length" class="mt-8">
            <h2 class="border-b border-b-rule pb-3 text-17">Stopped</h2>
            <ul class="divide-y divide-rule">
                <li v-for="row in stopped" :key="row.subject_id" class="flex flex-wrap items-baseline justify-between gap-3 py-3">
                    <p>
                        <span class="font-medium">{{ row.subject_name }}</span>
                        <span class="text-ink-2"> · {{ row.customer_name }}</span>
                    </p>
                    <Button variant="ghost" @click="router.post(route('overdue.resume', row.subject_id))">Start chasing again</Button>
                </li>
            </ul>
        </section>

        <section v-if="recent_sends.length" class="mt-8">
            <h2 class="border-b border-b-rule pb-3 text-17">Texts sent</h2>
            <p v-if="failedSends.length" class="max-w-measure pt-3 text-13 text-ink-2">
                {{ failedSends.length }} of these did not arrive. A number that keeps failing is usually one digit wrong —
                correcting it on the client's record starts the chasing again.
            </p>
            <ul class="divide-y divide-rule">
                <li v-for="send in recent_sends" :key="send.id" class="flex flex-wrap items-baseline justify-between gap-3 py-3">
                    <p>
                        <span class="font-medium">{{ send.customer_name ?? send.to }}</span>
                        <span class="text-ink-2"> · {{ send.to }}</span>
                        <span v-if="send.error" class="text-ink-2"> · {{ send.error }}</span>
                    </p>
                    <p class="text-13 text-ink-2">
                        <span class="numeral">{{ send.sent_on }}</span>
                        ·
                        <span :class="send.failed ? 'font-medium text-ink' : ''">{{ send.status }}</span>
                        <template v-if="send.segments > 1">
                            · <span class="numeral">{{ send.segments }}</span> texts
                        </template>
                    </p>
                </li>
            </ul>
        </section>

        <ConfirmDialog
            :show="confirmingEnable"
            title="Turn automatic messages on"
            tone="primary"
            :confirm-label="`Turn on for ${dry_run?.count ?? 0}`"
            @close="confirmingEnable = false"
            @confirm="confirmEnable"
        >
            Messages go out between {{ window }} in your own timezone, and each {{ noun }} is asked at most twice per
            cycle. You can turn this off again at any time.
        </ConfirmDialog>
    </AppLayout>
</template>
