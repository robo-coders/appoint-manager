<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{
    status: 'not_started' | 'in_progress' | 'ready';
    currently_due: string[];
    account_id: string | null;
    reachable: boolean;
}>();

const page = usePage();
const unreachable = computed(() => (page.props.errors as Record<string, string>)?.stripe);

const form = useForm({});
const connect = () => form.post(route('settings.payments.connect'));
</script>

<template>
    <AppLayout>
        <Head title="Payments" />
        <PageHeader title="Payments" description="Connect Stripe so deposits go to your account." />

        <SettingsNav current="payments" />

        <div class="mt-6 max-w-xl space-y-4">
            <!--
                The unreachable state. It is an error the owner did not cause and
                cannot fix, so it says what still works rather than what failed.
            -->
            <Callout v-if="unreachable" tone="danger" title="Stripe is not reachable">
                {{ unreachable }}
            </Callout>

            <p v-if="status === 'not_started'" class="text-14">
                Not connected. You can take bookings without deposits until you connect.
            </p>
            <p v-else-if="status === 'in_progress'" class="text-14">
                In progress. Stripe still needs: {{ currently_due.join(', ') || 'more details' }}.
            </p>
            <p v-else class="text-14">Ready. Charges are enabled on your connected account.</p>

            <p v-if="account_id" class="font-mono text-12 tabular-nums text-ink-2">{{ account_id }}</p>

            <div>
                <Button
                    type="button"
                    :disabled="form.processing || props.status === 'ready' || !props.reachable"
                    @click="connect"
                >
                    {{ props.status === 'not_started' ? 'Connect Stripe' : 'Continue setup' }}
                </Button>
                <p v-if="!props.reachable" class="mt-2 text-12 text-ink-2">
                    Connecting is unavailable until Stripe is configured for this installation.
                </p>
            </div>
        </div>
    </AppLayout>
</template>
