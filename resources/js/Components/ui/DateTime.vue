<script setup lang="ts">
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

/**
 * Absolute time, in the tenant's timezone, in mono tabular figures — with the
 * relative form *alongside* rather than instead of it, so "in 20 minutes" never
 * costs you the ability to read the actual time.
 */
const props = withDefaults(
    defineProps<{
        value: string;
        dateOnly?: boolean;
        timeOnly?: boolean;
        /** Append "in 20 minutes" / "2 days ago" when within a week. */
        relative?: boolean;
    }>(),
    { dateOnly: false, timeOnly: false, relative: false },
);

const page = usePage();
const timezone = computed(() => (page.props as { tenant?: { timezone?: string } }).tenant?.timezone ?? 'UTC');

// The API sends both ISO instants and pre-formatted 'Y-m-d H:i' local strings.
const parsed = computed(() => {
    const local = /^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})/.exec(props.value);
    if (local && !props.value.endsWith('Z') && !/[+-]\d{2}:?\d{2}$/.test(props.value)) {
        return { date: null as Date | null, parts: local };
    }
    const date = new Date(props.value);
    return { date: Number.isNaN(date.getTime()) ? null : date, parts: null };
});

const absolute = computed(() => {
    const { date, parts } = parsed.value;

    if (parts) {
        const [, y, m, d, hh, mm] = parts;
        if (props.timeOnly) return `${hh}:${mm}`;
        if (props.dateOnly) return `${d}/${m}/${y}`;
        return `${d}/${m}/${y} ${hh}:${mm}`;
    }
    if (!date) return props.value;

    return new Intl.DateTimeFormat('en-GB', {
        timeZone: timezone.value,
        ...(props.timeOnly ? {} : { day: '2-digit', month: 'short', year: 'numeric' }),
        ...(props.dateOnly ? {} : { hour: '2-digit', minute: '2-digit', hour12: false }),
    }).format(date);
});

const relativeText = computed(() => {
    if (!props.relative || !parsed.value.date) return '';
    const diff = parsed.value.date.getTime() - Date.now();
    const mins = Math.round(diff / 60000);
    if (Math.abs(mins) > 60 * 24 * 7) return '';
    const rtf = new Intl.RelativeTimeFormat('en-GB', { numeric: 'auto' });
    if (Math.abs(mins) < 60) return rtf.format(mins, 'minute');
    const hours = Math.round(mins / 60);
    if (Math.abs(hours) < 24) return rtf.format(hours, 'hour');
    return rtf.format(Math.round(hours / 24), 'day');
});
</script>

<template>
    <time :datetime="value" class="whitespace-nowrap">
        <span class="numeral">{{ absolute }}</span>
        <span v-if="relativeText" class="ml-1.5 text-ink-2">{{ relativeText }}</span>
    </time>
</template>
