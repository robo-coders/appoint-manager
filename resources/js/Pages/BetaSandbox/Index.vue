<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Card from '@/Components/ui/Card.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';

/**
 * Settings → Beta sandbox. See BETA_SANDBOX.md.
 *
 * **Written for somebody who has never used the word "database".** Every
 * sentence on this screen names a thing in her shop — customers, appointments,
 * the waitlist, her staff and services — and never a table, a job, a queue or a
 * timestamp. The two destructive buttons say what will be gone and what will
 * still be there, in that order, before she presses them.
 *
 * **Three states per action, and no fourth.** Pressing a button disables all
 * three and shows the pressed one's spinner (`Button` keeps its label in flow,
 * so nothing moves); success replaces the panel's own line with a plain-English
 * account of what happened, which stays on screen rather than sliding away like
 * the toast; a refusal comes back as a Callout at the top. There is no state in
 * which a press does nothing visible.
 *
 * The result line is captured from the flash message on success rather than
 * recomputed here, so the sentence the owner reads is the one the server
 * actually wrote — there is one copy of it, in `SandboxController`.
 */
defineProps<{
    shop: { customers: number; bookings: number };
    intervals: string[];
}>();

const page = usePage();

const failure = computed(() => (page.props.errors as Record<string, string>)?.sandbox);

/** Which panel last succeeded, and what the server said about it. */
const outcome = ref<{ panel: 'sample' | 'skip' | 'reset'; message: string } | null>(null);

const sample = useForm({});
const skip = useForm({ interval: 'day' });
const reset = useForm({});

const busy = computed(() => sample.processing || skip.processing || reset.processing);

const confirmingSample = ref(false);
const confirmingReset = ref(false);

/** The flash the redirect carried back, which is this action's own result. */
const said = () => (typeof page.props.toast === 'string' ? page.props.toast : '');

const loadSample = () => {
    confirmingSample.value = false;
    sample.post(route('beta-sandbox.sample-data'), {
        preserveScroll: true,
        onSuccess: () => {
            const message = said();
            outcome.value = message ? { panel: 'sample', message } : null;
        },
    });
};

const skipAhead = (interval: string) => {
    skip.interval = interval;
    skip.post(route('beta-sandbox.fast-forward'), {
        preserveScroll: true,
        onSuccess: () => {
            const message = said();
            outcome.value = message ? { panel: 'skip', message } : null;
        },
    });
};

const resetShop = () => {
    confirmingReset.value = false;
    reset.post(route('beta-sandbox.reset'), {
        preserveScroll: true,
        onSuccess: () => {
            const message = said();
            outcome.value = message ? { panel: 'reset', message } : null;
        },
    });
};

const label = (interval: string) => (interval === 'week' ? 'Skip 1 week' : 'Skip 1 day');
</script>

<template>
    <AppLayout>
        <Head title="Beta sandbox" />

        <PageHeader
            title="Beta sandbox"
            description="Practise on made-up customers. Nothing here can charge a card or text a real person."
        />

        <SettingsNav current="beta-sandbox" />

        <div class="mt-6 max-w-measure space-y-4">
            <!--
                The one thing that could refuse: a shop with nothing to build a
                diary from. Inline, in the owner's words, with the action she can
                take — never a raw error.
            -->
            <Callout v-if="failure" tone="danger" title="That did not run">{{ failure }}</Callout>

            <p class="text-14">
                Your shop has
                <span class="numeral">{{ shop.customers }}</span>
                {{ shop.customers === 1 ? 'customer' : 'customers' }} and
                <span class="numeral">{{ shop.bookings }}</span>
                {{ shop.bookings === 1 ? 'appointment' : 'appointments' }} in it.
            </p>

            <Card title="Sample data">
                <p class="text-13 text-ink-2">
                    Fills your shop with made-up customers and a couple of months of appointments — some finished, some
                    cancelled, some still to come — so there is something to look at. Everyone it invents has a phone
                    number that does not belong to anybody, and nothing is ever sent to them.
                </p>
                <p class="mt-2 text-13 text-ink-2">
                    Running it again starts over rather than piling more on top, so you can always get back to the same
                    tidy shop.
                </p>
                <p v-if="outcome?.panel === 'sample'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                <div class="mt-4">
                    <Button :loading="sample.processing" :disabled="busy" @click="confirmingSample = true">
                        Load sample data
                    </Button>
                </div>
            </Card>

            <Card title="Fast-forward time">
                <p class="text-13 text-ink-2">
                    Moves your shop forward so you can see what happens next without waiting for it. Appointments that
                    were a week away become a day away, reminders that were due go out, unpaid holds are let go, and
                    waitlist offers that nobody claimed run out.
                </p>
                <p class="mt-2 text-13 text-ink-2">
                    It only moves your shop. The real date does not change, and no other salon is affected.
                </p>
                <p v-if="outcome?.panel === 'skip'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                <div class="mt-4 flex flex-wrap gap-2">
                    <Button
                        v-for="interval in intervals"
                        :key="interval"
                        variant="secondary"
                        :loading="skip.processing && skip.interval === interval"
                        :disabled="busy"
                        @click="skipAhead(interval)"
                    >
                        {{ label(interval) }}
                    </Button>
                </div>
            </Card>

            <Card title="Start again">
                <p class="text-13 text-ink-2">
                    Empties the shop of customers, appointments and everything on the waitlist. Your login, your staff,
                    your services, your opening hours and your settings all stay exactly as they are — this is not
                    closing your account.
                </p>
                <p v-if="outcome?.panel === 'reset'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                <div class="mt-4">
                    <Button variant="danger" :loading="reset.processing" :disabled="busy" @click="confirmingReset = true">
                        Reset my shop
                    </Button>
                </div>
            </Card>
        </div>

        <!--
            Both dialogs name the consequence rather than asking "are you sure".
            Sample data gets one because it replaces what is there — a button
            that quietly deleted a shop somebody had been setting up by hand
            would be the worst thing on this screen.
        -->
        <ConfirmDialog
            :show="confirmingSample"
            title="Replace what is in your shop?"
            confirm-label="Load the sample shop"
            cancel-label="Leave it as it is"
            :loading="sample.processing"
            @close="confirmingSample = false"
            @confirm="loadSample"
        >
            This clears the customers, appointments and waitlist you have now and puts a made-up shop in their place.
            Your staff, services, opening hours and settings are not touched. It cannot be undone.
        </ConfirmDialog>

        <ConfirmDialog
            :show="confirmingReset"
            title="Empty this shop?"
            confirm-label="Reset my shop"
            cancel-label="Keep everything"
            :loading="reset.processing"
            @close="confirmingReset = false"
            @confirm="resetShop"
        >
            This deletes all customers, bookings and waitlist entries for this shop. Your login and shop settings stay.
            This cannot be undone.
        </ConfirmDialog>
    </AppLayout>
</template>
