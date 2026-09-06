<script setup lang="ts">
import Badge from '@/Components/ui/Badge.vue';
import DateTime from '@/Components/ui/DateTime.vue';

defineProps<{
    messages: Array<{
        id: number;
        at: string;
        recipient: string;
        badge: string;
        body: string;
    }>;
}>();

const tone = (badge: string): 'confirmed' | 'pending' | 'cancelled' | 'neutral' | 'accent' => {
    if (badge === 'No-show' || badge === 'Cancelled') return 'cancelled';
    if (badge === 'Waitlist offer') return 'accent';
    if (badge === 'Reminder' || badge === 'Loyalty') return 'confirmed';
    return 'neutral';
};
</script>

<template>
    <div
        v-if="messages.length === 0"
        class="rounded border border-rule bg-paper-sunk px-4 py-6 text-13 text-ink-2"
    >
        No messages yet — trigger an action above to see one appear here.
    </div>
    <ul v-else class="max-h-[30rem] divide-y divide-rule overflow-y-auto border-t border-rule">
        <li v-for="row in messages" :key="row.id" class="py-2">
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <div class="flex flex-wrap items-baseline gap-x-2 gap-y-1">
                        <DateTime :value="row.at" class="text-12 text-ink-2" />
                        <span class="text-13 text-ink">{{ row.recipient }}</span>
                    </div>
                    <p class="mt-1 text-13 text-ink-2">{{ row.body }}</p>
                </div>
                <Badge :tone="tone(row.badge)">{{ row.badge }}</Badge>
            </div>
        </li>
    </ul>
</template>
