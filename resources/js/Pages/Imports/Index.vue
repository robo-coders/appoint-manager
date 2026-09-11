<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Badge from '@/Components/ui/Badge.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import EmptyState from '@/Components/ui/EmptyState.vue';
import FileDrop from '@/Components/ui/FileDrop.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Table, { type Column } from '@/Components/ui/Table.vue';
import Tabs from '@/Components/ui/Tabs.vue';
import Textarea from '@/Components/ui/Textarea.vue';
import { Head, router } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

type Row = { row: number; ok: boolean; message: string; [key: string]: unknown };

type Result = {
    kind: 'customers' | 'bookings';
    committed: boolean;
    ok: number;
    failed: number;
    rows: Row[];
    sampled: boolean;
};

const props = defineProps<{
    result: Result | null;
    columns: { customers: string[]; bookings: string[] };
}>();

const kind = ref<'customers' | 'bookings'>(props.result?.kind ?? 'customers');

const csv = ref<Record<string, string>>({ customers: '', bookings: '' });
const fileName = ref<Record<string, string | null>>({ customers: null, bookings: null });
const busy = ref(false);

const expected = computed(() => props.columns[kind.value]);

const readFile = (file: File) => {
    const reader = new FileReader();
    reader.onload = () => {
        csv.value[kind.value] = String(reader.result ?? '');
        fileName.value[kind.value] = file.name;
    };
    reader.readAsText(file);
};

const lines = computed(() =>
    csv.value[kind.value]
        .split(/\r\n|\n|\r/)
        .map((line) => line.trim())
        .filter((line) => line !== ''),
);

const cells = (line: string) =>
    (line.match(/("([^"]|"")*"|[^,]*)(,|$)/g) ?? [])
        .map((cell) => cell.replace(/,$/, '').trim().replace(/^"|"$/g, '').replace(/""/g, '"'))
        .slice(0, expected.value.length);

const hasHeader = computed(() => lines.value[0]?.toLowerCase().includes('email') ?? false);

const headerCells = computed(() => (hasHeader.value ? cells(lines.value[0]) : []));
const dataLines = computed(() => (hasHeader.value ? lines.value.slice(1) : lines.value));

const mapping = computed(() =>
    expected.value.map((column, index) => ({
        column,
        found: hasHeader.value ? (headerCells.value[index] ?? null) : null,
        sample: dataLines.value[0] ? (cells(dataLines.value[0])[index] ?? '') : '',
    })),
);

const mismatch = computed(() =>
    hasHeader.value
        ? mapping.value.some(
              (entry) => entry.found !== null && entry.found.toLowerCase().replace(/[\s_-]/g, '') !== entry.column.replace(/_/g, ''),
          )
        : false,
);

const run = (commit: boolean) => {
    busy.value = true;
    router.post(
        kind.value === 'customers' ? route('imports.customers') : route('imports.bookings'),
        { csv: csv.value[kind.value], commit },
        { preserveScroll: true, onFinish: () => (busy.value = false) },
    );
};

const dryRunDone = computed(() => props.result !== null && props.result.kind === kind.value && !props.result.committed);

const resultColumns: Column[] = [
    { key: 'row', label: 'Row', width: 'time', align: 'right', numeric: true },
    { key: 'state', label: 'Result', width: 'status' },
    { key: 'message', label: 'What happens' },
];

const resultRows = computed(() =>
    (props.result?.rows ?? []).map((row) => ({ ...row, state: row.ok ? 'Ready' : 'Skipped' })),
);
</script>

<template>
    <AppLayout>
        <Head title="Import" />
        <PageHeader
            title="Import"
            description="Bring your customers and your diary across. Nothing is saved until you say so."
        />

        <Tabs
            v-model="kind"
            :tabs="[
                { value: 'customers', label: 'Customers' },
                { value: 'bookings', label: 'Bookings' },
            ]"
            label="What to import"
        />

        <div class="mt-6 grid gap-8 lg:grid-cols-2">
            <div class="space-y-4">
                <FileDrop
                    :label="kind === 'customers' ? 'Customer CSV' : 'Booking CSV'"
                    accept=".csv,text/csv"
                    :file-name="fileName[kind]"
                    :hint="`Columns, in order: ${expected.join(', ')}`"
                    @file="readFile"
                />

                <Textarea
                    v-model="csv[kind]"
                    label="Or paste it"
                    :rows="6"
                    hint="One row per line. Twelve customers in a spreadsheet do not need saving to a file first."
                />

                <div class="flex flex-wrap items-center gap-3">
                    <Button variant="secondary" :loading="busy" :disabled="lines.length === 0" @click="run(false)">
                        Dry run
                    </Button>
                    <Button
                        :loading="busy"
                        :disabled="!dryRunDone"
                        aria-describedby="import-gate"
                        @click="run(true)"
                    >
                        Import {{ dryRunDone ? `${result?.ok} row${result?.ok === 1 ? '' : 's'}` : '' }}
                    </Button>
                </div>
                <p id="import-gate" class="caption">
                    {{ dryRunDone ? 'Checked. Importing will create these rows.' : 'Dry run first — nothing can be imported until it has been checked.' }}
                </p>
            </div>

            <div>
                <h2 class="border-b border-b-rule pb-2 text-17">Columns</h2>

                <EmptyState
                    v-if="lines.length === 0"
                    class="mt-4"
                    title="Nothing loaded yet"
                    description="Drop a file or paste some rows and the columns we read will be listed here, with the first row of your file beside them."
                />

                <template v-else>
                    <Callout v-if="mismatch" tone="danger" title="These headers do not look right">
                        Your file's header row does not match the order we read. The columns are read by
                        <strong class="font-medium">position</strong>, not by name — reorder them, or delete the header row
                        and check the samples below.
                    </Callout>

                    <ul class="mt-2">
                        <li
                            v-for="entry in mapping"
                            :key="entry.column"
                            class="flex min-h-row items-baseline gap-4 border-b border-b-rule py-2"
                        >
                            <span class="w-col-staff shrink-0 text-13 text-ink-2">{{ entry.column }}</span>
                            <span class="flex-1 truncate text-14">
                                {{ entry.sample || '—' }}
                            </span>
                            <span v-if="entry.found" class="shrink-0 text-12 text-ink-2">from "{{ entry.found }}"</span>
                        </li>
                    </ul>

                    <p class="caption mt-2">
                        <span class="numeral">{{ dataLines.length }}</span> row{{ dataLines.length === 1 ? '' : 's' }}
                        to import{{ hasHeader ? ', after the header row' : ', and no header row was found' }}.
                    </p>
                </template>
            </div>
        </div>

        <template v-if="result && result.kind === kind">
            <h2 class="mt-8 border-b border-b-rule pb-2 text-17">
                {{ result.committed ? 'Imported' : 'Dry run' }}
            </h2>

            <div class="mt-4 flex flex-wrap gap-6">
                <div>
                    <p class="caption">{{ result.committed ? 'Created' : 'Would be created' }}</p>
                    <p class="numeral mt-1 text-24 font-medium">{{ result.ok }}</p>
                </div>
                <div>
                    <p class="caption">Skipped</p>
                    <p class="numeral mt-1 text-24 font-medium" :class="result.failed ? 'text-danger' : ''">
                        {{ result.failed }}
                    </p>
                </div>
            </div>

            <Callout v-if="!result.committed && result.failed" tone="neutral" class="mt-4">
                Skipped rows are left alone — importing goes ahead with the rest. Fix them in your file and run it
                again if you want them too.
            </Callout>

            <div class="mt-4">
                <Table
                    :columns="resultColumns"
                    :rows="resultRows"
                    label="Import result"
                    empty-title="Nothing in this file"
                    empty-description="Every line was blank or a header."
                >
                    <template #cell:row="{ row }">
                        <span class="numeral">{{ row.row }}</span>
                    </template>
                    <template #cell:state="{ row }">
                        <Badge :tone="row.ok ? 'confirmed' : 'cancelled'">{{ row.state }}</Badge>
                    </template>

                    <template #footer>
                        <span v-if="result.sampled">
                            Showing every skipped row, and the first
                            <span class="numeral">20</span> that are ready.
                        </span>
                        <span v-else>Showing every row.</span>
                    </template>
                </Table>
            </div>
        </template>
    </AppLayout>
</template>
