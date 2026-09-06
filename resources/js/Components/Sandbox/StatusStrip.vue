<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    summary: {
        no_shows: number;
        pending_offers: number;
        expired_holds: number;
        outbox: number;
        last_action: { label: string; at: string } | null;
    };
    shop?: { customers: number; bookings: number };
}>();

const ago = computed(() => {
    const at = props.summary.last_action?.at;
    if (!at) return '';
    const mins = Math.round((Date.now() - new Date(at).getTime()) / 60000);
    if (!Number.isFinite(mins) || mins < 1) return 'just now';
    if (mins === 1) return '1 minute ago';
    if (mins < 60) return `${mins} minutes ago`;
    const hours = Math.round(mins / 60);
    if (hours === 1) return '1 hour ago';
    if (hours < 24) return `${hours} hours ago`;
    const days = Math.round(hours / 24);
    return days === 1 ? '1 day ago' : `${days} days ago`;
});

const rows = computed(() => [
    { label: 'No-shows', value: props.summary.no_shows },
    { label: 'Pending offers', value: props.summary.pending_offers },
    { label: 'Expired holds', value: props.summary.expired_holds },
    { label: 'SMS outbox', value: props.summary.outbox },
]);

const spoken = computed(() => {
    const parts = rows.value.map((row) => `${row.value} ${row.label.toLowerCase()}`);
    if (props.summary.last_action) {
        parts.push(`Last action: ${props.summary.last_action.label}, ${ago.value}`);
    }
    return parts.join('. ');
});
</script>

<template>
    <section class="rounded border border-rule bg-white p-4" aria-label="Sandbox status">
        <h2 class="text-15 text-ink">Status</h2>
        <p class="sr-only" role="status" aria-atomic="true">{{ spoken }}</p>
        <dl class="mt-3 divide-y divide-rule" aria-hidden="true">
            <div v-for="row in rows" :key="row.label" class="flex items-baseline justify-between gap-4 py-2 first:pt-0 last:pb-0">
                <dt class="caption">{{ row.label }}</dt>
                <dd class="numeral text-15 text-ink">{{ row.value }}</dd>
            </div>
        </dl>
        <div v-if="shop" class="mt-3 border-t border-rule pt-3">
            <p class="caption">In the shop</p>
            <p class="mt-1 text-13 text-ink">
                <span class="numeral">{{ shop.customers }}</span>
                {{ shop.customers === 1 ? 'customer' : 'customers' }}
                <span class="text-ink-2"> · </span>
                <span class="numeral">{{ shop.bookings }}</span>
                {{ shop.bookings === 1 ? 'appointment' : 'appointments' }}
            </p>
        </div>
        <div class="mt-3 border-t border-rule pt-3">
            <p class="caption">Last action</p>
            <p v-if="summary.last_action" class="mt-1 text-13 text-ink">
                {{ summary.last_action.label }}
                <span class="mt-0.5 block">
                    <time class="numeral text-ink-2" :datetime="summary.last_action.at">{{ ago }}</time>
                </span>
            </p>
            <p v-else class="mt-1 text-13 text-ink-2">No sandbox action yet.</p>
        </div>
    </section>
</template>
