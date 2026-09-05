<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Badge from '@/Components/ui/Badge.vue';
import { Head, Link } from '@inertiajs/vue3';

/**
 * One customer's record.
 *
 * The loyalty section is the owner-side half of loyalty packages, and one of
 * only two places the stamps are ever visible — there is no customer portal, so
 * this screen and the booking confirmation text are the whole of it. `loyalty`
 * is null when the tenant has the feature off *or* when this customer has not
 * booked since it went on, and the section is absent in both cases rather than
 * empty: a heading over "not enrolled" is a row of noise on a screen an owner
 * reads between appointments.
 */
defineProps<{
    customer: {
        id: number;
        name: string;
        email: string | null;
        phone: string | null;
        notes: string | null;
        subjects: Array<{ id: number; name: string; attributes: Record<string, string> }>;
        bookings: Array<{
            id: number;
            service_name: string;
            starts_at_local: string;
            status: string;
            is_loyalty_reward: boolean;
        }>;
    };
    loyalty: {
        package_name: string | null;
        reward: string | null;
        sessions_required: number | null;
        stamps_used: number;
        remaining: number;
        reward_due: boolean;
        earning: boolean;
        cycles_completed: number;
        free_sessions: Array<{
            id: number;
            service_name: string | null;
            starts_at_local: string | null;
            status: string;
        }>;
    } | null;
}>();
</script>

<template>
    <AppLayout>
        <Head :title="customer.name" />
        <PageHeader :title="customer.name" :description="customer.email ?? undefined" />
        <div class="grid gap-6 md:grid-cols-2">
            <section class="rounded border border-rule bg-white p-6">
                <h2 class="text-14 font-medium">Details</h2>
                <p class="mt-2 text-14">{{ customer.email || 'No email' }}</p>
                <p class="mt-2 text-14">{{ customer.phone || 'No phone' }}</p>
                <ul class="mt-4 space-y-2 text-14">
                    <li v-for="subject in customer.subjects" :key="subject.id">{{ subject.name }}</li>
                    <li v-if="customer.subjects.length === 0" class="text-ink-2">None yet.</li>
                </ul>
            </section>
            <section class="rounded border border-rule bg-white p-6">
                <h2 class="text-14 font-medium">History</h2>
                <ul class="mt-4 space-y-2 text-14">
                    <li v-for="booking in customer.bookings" :key="booking.id">
                        <Link :href="route('bookings.show', booking.id)" class="underline">
                            {{ booking.starts_at_local }} · {{ booking.service_name }}
                        </Link>
                        <span class="text-ink-2"> {{ booking.status }}</span>
                        <!-- Why a £0 appointment was £0, in the list as well as
                             on the booking itself. -->
                        <span v-if="booking.is_loyalty_reward" class="text-ink-2"> · free</span>
                    </li>
                    <li v-if="customer.bookings.length === 0" class="text-ink-2">No bookings yet.</li>
                </ul>
                <div class="mt-6 flex gap-4 text-13">
                    <a :href="route('customers.export', customer.id)" class="underline">Export data</a>
                    <Link
                        :href="route('customers.destroy', customer.id)"
                        method="delete"
                        as="button"
                        class="text-ink-2 underline"
                    >
                        Delete record
                    </Link>
                </div>
            </section>
        </div>

        <!--
            The loyalty card. Absent, not empty, when there is nothing to say —
            see the block comment at the top of this file.
        -->
        <section v-if="loyalty" class="mt-6 rounded border border-rule bg-white p-6">
            <div class="flex flex-wrap items-baseline justify-between gap-3">
                <h2 class="text-14 font-medium">{{ loyalty.package_name ?? 'Loyalty' }}</h2>
                <!--
                    Never colour alone: each badge carries its own label, per
                    DESIGN.md. The accent is spent here and only here on this
                    screen, on the one state that needs an action — the next
                    appointment is free and the owner should not charge for it.
                -->
                <Badge v-if="!loyalty.earning" tone="neutral">Paused</Badge>
                <Badge v-else-if="loyalty.reward_due" tone="accent">Next one is free</Badge>
                <Badge v-else tone="confirmed">Collecting</Badge>
            </div>

            <p class="mt-2 text-14">
                <span class="numeral">{{ loyalty.stamps_used }}</span>
                of
                <span class="numeral">{{ loyalty.sessions_required ?? 0 }}</span>
                stamps used
            </p>

            <p class="mt-1 text-13 text-ink-2">
                <template v-if="!loyalty.earning">
                    The package behind this card is switched off, so it is not collecting. Switching loyalty back on
                    in settings moves them onto the current package and keeps what they have already earned.
                </template>
                <template v-else-if="loyalty.reward_due">
                    {{ loyalty.reward ?? 'The next session is free' }}. Their next booking is priced at zero and
                    skips the deposit on its own — there is nothing to do here.
                </template>
                <template v-else>
                    <span class="numeral">{{ loyalty.remaining }}</span>
                    more until {{ (loyalty.reward ?? 'the next session is free').toLowerCase() }}.
                </template>
            </p>

            <p v-if="loyalty.cycles_completed > 0" class="mt-4 text-13 text-ink-2">
                <span class="numeral">{{ loyalty.cycles_completed }}</span>
                full card{{ loyalty.cycles_completed === 1 ? '' : 's' }} so far.
            </p>

            <!-- The history: the appointments the stamps actually paid for. -->
            <ul v-if="loyalty.free_sessions.length" class="mt-2 space-y-1 text-13">
                <li v-for="session in loyalty.free_sessions" :key="session.id">
                    <Link :href="route('bookings.show', session.id)" class="underline">
                        {{ session.starts_at_local }} · {{ session.service_name }}
                    </Link>
                    <span class="text-ink-2"> free · {{ session.status }}</span>
                </li>
            </ul>
        </section>
    </AppLayout>
</template>
