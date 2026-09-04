<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

export type PendingRequest = {
    id: number;
    time: string;
    date: string;
    customer: string | null;
    subject: string | null;
    service: string | null;
    staff: string | null;
    amount: string;
    expires_at: string | null;
};

defineProps<{ requests: PendingRequest[] }>();

const deciding = ref<number | null>(null);

const decide = (id: number, action: 'approve' | 'decline') => {
    if (deciding.value !== null) return;
    deciding.value = id;
    router.post(route(`bookings.${action}`, id), {}, {
        preserveScroll: true,
        onFinish: () => {
            deciding.value = null;
        },
    });
};
</script>

<template>
    <section v-if="requests.length > 0">
        <h2 class="border-b border-b-rule pb-3 text-17">Pending requests</h2>
        <ul>
            <li
                v-for="row in requests"
                :key="row.id"
                class="flex flex-wrap items-baseline gap-3 border-b border-b-rule py-3"
            >
                <span class="numeral w-col-time shrink-0 text-14 font-medium">{{ row.time }}</span>
                <span class="min-w-0 flex-1 text-14">
                    <span class="block">{{ row.subject ?? row.customer }} — {{ row.service }}</span>
                    <span class="caption mt-0.5 block">
                        {{ row.date }} · {{ row.staff }} · {{ row.amount }}
                        <template v-if="row.expires_at"> · expires {{ row.expires_at }}</template>
                    </span>
                </span>
                <span class="flex shrink-0 gap-2">
                    <Button
                        variant="secondary"
                        :loading="deciding === row.id"
                        :disabled="deciding !== null"
                        @click="decide(row.id, 'decline')"
                    >
                        Decline
                    </Button>
                    <Button
                        :loading="deciding === row.id"
                        :disabled="deciding !== null"
                        @click="decide(row.id, 'approve')"
                    >
                        Approve
                    </Button>
                </span>
            </li>
        </ul>
    </section>
</template>
