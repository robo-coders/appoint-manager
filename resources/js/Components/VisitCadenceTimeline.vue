<script setup lang="ts">
import Badge from '@/Components/ui/Badge.vue';
import { Link } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

export type CadenceMark = {
    booking_id: number;
    date: string;
    label: string;
    time: string;
    outcome: 'attended' | 'no_show' | 'booked';
    service_name: string | null;
    staff_name: string | null;
    paid: string;
    gap_days: number | null;
};

const props = defineProps<{ visits: CadenceMark[] }>();

const MONTHS = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];

const at = (iso: string) => new Date(`${iso}T12:00:00`).getTime();

const dated = computed(() => props.visits.filter((visit) => !Number.isNaN(at(visit.date))));

const bounds = computed(() => {
    const times = dated.value.map((visit) => at(visit.date));

    return { first: Math.min(...times), last: Math.max(...times) };
});

const single = computed(() => dated.value.length < 2 || bounds.value.last === bounds.value.first);

const positionOf = (iso: string) => {
    if (single.value) return 50;

    const { first, last } = bounds.value;

    return 4 + ((at(iso) - first) / (last - first)) * 92;
};

const marks = computed(() =>
    dated.value.map((visit, index) => ({
        visit,
        index,
        left: `${positionOf(visit.date).toFixed(2)}%`,
    })),
);

const gaps = computed(() => {
    const spans = marks.value.slice(1).map((mark) => mark.visit.gap_days ?? 0);
    const mean = spans.length === 0 ? 0 : spans.reduce((total, span) => total + span, 0) / spans.length;
    const worth = (span: number) => spans.length <= 2 || span >= mean * 1.25;

    return marks.value
        .slice(1)
        .map((mark, index) => ({
            key: mark.visit.booking_id,
            days: mark.visit.gap_days ?? 0,
            label: `${mark.visit.gap_days ?? 0}d`,
            left: `${((positionOf(marks.value[index].visit.date) + positionOf(mark.visit.date)) / 2).toFixed(2)}%`,
        }))
        .filter((gap) => gap.days > 0 && worth(gap.days));
});

const LABEL_CEILING = 6;

const ticks = computed(() => {
    if (single.value) return [];

    const { first, last } = bounds.value;
    const cursor = new Date(first);
    cursor.setDate(1);
    const out: Array<{ key: string; left: string; month: number; year: number; labelled: boolean; label: string }> = [];

    while (cursor.getTime() <= last) {
        const left = 4 + ((cursor.getTime() - first) / (last - first)) * 92;

        if (left >= 0 && left <= 100) {
            out.push({
                key: `${cursor.getFullYear()}-${cursor.getMonth()}`,
                left: `${left.toFixed(2)}%`,
                month: cursor.getMonth(),
                year: cursor.getFullYear(),
                labelled: false,
                label: MONTHS[cursor.getMonth()] + (cursor.getMonth() === 0 ? ` ${String(cursor.getFullYear()).slice(2)}` : ''),
            });
        }

        cursor.setMonth(cursor.getMonth() + 1);
    }

    const everyMonth = out.length <= LABEL_CEILING;

    return out.map((tick) => ({ ...tick, labelled: everyMonth || tick.month % 3 === 0 }));
});

const selected = ref(0);

watch(
    () => props.visits,
    () => (selected.value = Math.max(0, dated.value.length - 1)),
    { immediate: true },
);

const current = computed(() => dated.value[selected.value] ?? null);

const toneFor = (outcome: string) => (outcome === 'no_show' ? 'cancelled' : 'neutral');

const OUTCOME_LABELS: Record<string, string> = {
    attended: 'Attended',
    no_show: 'No show',
    booked: 'Booked',
};
</script>

<template>
    <section class="rounded border border-rule bg-white p-4">
        <div class="mb-6 flex flex-wrap items-baseline justify-between gap-3">
            <div>
                <h2 class="text-14 font-medium">Visit cadence</h2>
                <p class="caption mt-0.5">Marks sit on the date they happened, so the gaps are real.</p>
            </div>
            <ul class="flex flex-wrap items-center gap-4 text-12 text-ink-2">
                <li class="flex items-center gap-2">
                    <span class="size-2 rounded bg-ink" aria-hidden="true" />
                    attended
                </li>
                <li class="flex items-center gap-2">
                    <span class="size-2 rounded border border-danger" aria-hidden="true" />
                    no-show
                </li>
                <li class="flex items-center gap-2">
                    <span class="size-2 rounded border border-dashed border-ink-3" aria-hidden="true" />
                    booked
                </li>
            </ul>
        </div>

        <p v-if="dated.length === 0" class="text-13 text-ink-2">
            No visits yet. The timeline fills in from the first appointment.
        </p>

        <div v-else class="relative mx-3 h-16">
            <span class="absolute inset-x-0 top-8 h-px bg-rule-strong" aria-hidden="true" />

            <span
                v-for="tick in ticks"
                :key="`tick-${tick.key}`"
                class="absolute top-8 w-px bg-rule-strong"
                :class="tick.labelled ? 'h-2' : 'h-1'"
                :style="{ left: tick.left }"
                aria-hidden="true"
            />
            <span
                v-for="tick in ticks.filter((one) => one.labelled)"
                :key="`label-${tick.key}`"
                class="numeral absolute top-12 -translate-x-1/2 whitespace-nowrap text-12 text-ink-3"
                :style="{ left: tick.left }"
                aria-hidden="true"
                >{{ tick.label }}</span
            >

            <span
                v-for="gap in gaps"
                :key="`gap-${gap.key}`"
                class="numeral absolute top-1 -translate-x-1/2 whitespace-nowrap text-12 text-ink-3"
                :style="{ left: gap.left }"
                data-testid="cadence-gap"
                >{{ gap.label }}</span
            >

            <span
                v-for="mark in marks"
                :key="mark.visit.booking_id"
                class="absolute top-8 -translate-x-1/2 -translate-y-1/2"
                :style="{ left: mark.left }"
                data-testid="cadence-mark"
                @mouseenter="selected = mark.index"
            >
                <Link
                    :href="route('bookings.show', mark.visit.booking_id)"
                    class="grid size-6 place-items-center rounded"
                    :aria-label="`${mark.visit.label} · ${mark.visit.service_name ?? 'visit'} · ${OUTCOME_LABELS[mark.visit.outcome]}`"
                    @focus="selected = mark.index"
                >
                    <span
                        class="rounded transition duration-fast ease-product"
                        :class="[
                            selected === mark.index ? 'size-3' : 'size-2',
                            mark.visit.outcome === 'attended' ? 'bg-ink' : '',
                            mark.visit.outcome === 'no_show' ? 'border border-danger' : '',
                            mark.visit.outcome === 'booked' ? 'border border-dashed border-ink-3' : '',
                        ]"
                        aria-hidden="true"
                    />
                </Link>
            </span>
        </div>

        <div
            v-if="current"
            class="mt-4 flex flex-wrap items-baseline gap-3 border-t border-rule pt-3"
            data-testid="cadence-detail"
        >
            <span class="numeral text-14">{{ current.label }}</span>
            <span class="text-13 text-ink">{{ current.service_name ?? '—' }}</span>
            <span v-if="current.staff_name" class="text-13 text-ink-2">with {{ current.staff_name }}</span>
            <Badge :tone="toneFor(current.outcome)">{{ OUTCOME_LABELS[current.outcome] }}</Badge>
            <span class="numeral text-13 text-ink-2">{{ current.paid }}</span>
        </div>
    </section>
</template>
