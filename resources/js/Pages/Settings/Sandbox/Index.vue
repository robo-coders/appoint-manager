<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import LearnMore from '@/Components/Sandbox/LearnMore.vue';
import SmsOutbox from '@/Components/Sandbox/SmsOutbox.vue';
import StatusStrip from '@/Components/Sandbox/StatusStrip.vue';
import Button from '@/Components/ui/Button.vue';
import Callout from '@/Components/ui/Callout.vue';
import Card from '@/Components/ui/Card.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Select from '@/Components/ui/Select.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import Toggle from '@/Components/ui/Toggle.vue';
import { Head, useForm, usePage } from '@inertiajs/vue3';
import { computed, ref, watch } from 'vue';

type Size = { key: string; label: string; customers: number; bookings: number };
type Candidate = { id: number; label: string };
type Summary = {
    no_shows: number;
    pending_offers: number;
    expired_holds: number;
    outbox: number;
    last_action: { label: string; at: string } | null;
};
type OutboxRow = { id: number; at: string; recipient: string; badge: string; body: string };

const props = defineProps<{
    shop: { customers: number; bookings: number };
    intervals: string[];
    sizes: Size[];
    summary: Summary;
    outbox: OutboxRow[];
    candidates: Candidate[];
    flaky_network: boolean;
    jump_min: string;
}>();

const page = usePage();
const failure = computed(() => (page.props.errors as Record<string, string>)?.sandbox);

type Panel = 'sample' | 'skip' | 'noshow' | 'waitlist' | 'outbox' | 'remind' | 'flaky' | 'reset';
const outcome = ref<{ panel: Panel; message: string } | null>(null);

const sample = useForm({ size: 'typical' });
const skip = useForm({ interval: 'day' });
const jump = useForm({ date: props.jump_min });
const noShow = useForm({ booking_id: props.candidates[0]?.id ?? '' });
const waitFree = useForm({});
const waitExpire = useForm({});
const remind = useForm({});
const outboxClear = useForm({});
const flaky = useForm({ enabled: props.flaky_network });
const reset = useForm({});

watch(
    () => props.candidates,
    (rows) => {
        if (!rows.some((row) => String(row.id) === String(noShow.booking_id))) {
            noShow.booking_id = rows[0]?.id ?? '';
        }
    },
);

watch(
    () => props.flaky_network,
    (value) => {
        flaky.enabled = value;
    },
);

const busy = computed(
    () =>
        sample.processing ||
        skip.processing ||
        jump.processing ||
        noShow.processing ||
        waitFree.processing ||
        waitExpire.processing ||
        remind.processing ||
        outboxClear.processing ||
        flaky.processing ||
        reset.processing,
);

const confirmingSample = ref(false);
const confirmingReset = ref(false);
const confirmingOutbox = ref(false);
const chosenSize = ref<Size>(props.sizes.find((size) => size.key === 'typical') ?? props.sizes[0]);

const visit = { preserveScroll: true };

const said = () => (typeof page.props.toast === 'string' ? page.props.toast : '');

const succeed = (panel: Panel) => {
    const message = said();
    outcome.value = message ? { panel, message } : null;
};

const askSample = (size: Size) => {
    chosenSize.value = size;
    sample.size = size.key;
    confirmingSample.value = true;
};

const loadSample = () => {
    confirmingSample.value = false;
    sample.post(route('beta-sandbox.sample-data'), { ...visit, onSuccess: () => succeed('sample') });
};

const skipAhead = (interval: string) => {
    skip.interval = interval;
    skip.post(route('beta-sandbox.fast-forward'), { ...visit, onSuccess: () => succeed('skip') });
};

const jumpToDate = () => {
    jump.post(route('beta-sandbox.jump'), { ...visit, onSuccess: () => succeed('skip') });
};

const markNoShow = () => {
    noShow.post(route('beta-sandbox.no-show'), { ...visit, onSuccess: () => succeed('noshow') });
};

const freeSlot = () => {
    waitFree.post(route('beta-sandbox.waitlist-free'), { ...visit, onSuccess: () => succeed('waitlist') });
};

const expireOffer = () => {
    waitExpire.post(route('beta-sandbox.waitlist-expire'), { ...visit, onSuccess: () => succeed('waitlist') });
};

const sendReminders = () => {
    remind.post(route('beta-sandbox.remind'), { ...visit, onSuccess: () => succeed('remind') });
};

const clearOutbox = () => {
    confirmingOutbox.value = false;
    outboxClear.post(route('beta-sandbox.outbox-clear'), { ...visit, onSuccess: () => succeed('outbox') });
};

const toggleFlaky = (value: boolean) => {
    flaky.enabled = value;
    flaky.post(route('beta-sandbox.flaky'), { ...visit, onSuccess: () => succeed('flaky') });
};

const resetShop = () => {
    confirmingReset.value = false;
    reset.post(route('beta-sandbox.reset'), { ...visit, onSuccess: () => succeed('reset') });
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

        <div class="mt-6">
            <div v-if="failure" class="mb-4">
                <Callout tone="danger" title="That did not run">{{ failure }}</Callout>
            </div>

            <div class="grid items-start gap-4 lg:grid-cols-[minmax(0,1.85fr)_minmax(16rem,1fr)] lg:gap-6">
                <aside class="lg:col-start-2 lg:row-start-1 lg:sticky lg:top-6">
                    <StatusStrip :summary="summary" :shop="shop" />
                </aside>

                <div class="min-w-0 space-y-8 lg:col-start-1 lg:row-start-1">
                    <section class="space-y-4" aria-labelledby="sandbox-shop">
                        <h2 id="sandbox-shop" class="caption">Shop</h2>
                        <Card>
                            <h3 class="text-15 text-ink">Sample data</h3>
                            <p class="mt-1 text-13 text-ink-2">Replace the shop with a made-up diary. Running a size again starts over.</p>
                            <LearnMore>
                                <p>
                                    Everyone it invents has a phone number that does not belong to anybody, and nothing is
                                    ever sent to them.
                                </p>
                                <p>Each load includes Pat Cardwell, whose card always declines — use them to try a failed deposit.</p>
                                <p>
                                    Quiet is
                                    <span class="numeral">5</span>
                                    customers. Typical is
                                    <span class="numeral">24</span>
                                    customers and
                                    <span class="numeral">115</span>
                                    appointments. Busy is a packed diary with a fully booked day.
                                </p>
                            </LearnMore>
                            <p v-if="outcome?.panel === 'sample'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Button
                                    v-for="size in sizes"
                                    :key="size.key"
                                    :variant="size.key === 'typical' ? 'primary' : 'secondary'"
                                    :loading="sample.processing && sample.size === size.key"
                                    :disabled="busy"
                                    @click="askSample(size)"
                                >
                                    {{ size.label }}
                                </Button>
                            </div>
                        </Card>
                    </section>

                    <section class="space-y-4" aria-labelledby="sandbox-time">
                        <h2 id="sandbox-time" class="caption">Time</h2>
                        <Card>
                            <h3 class="text-15 text-ink">Fast-forward time</h3>
                            <p class="mt-1 text-13 text-ink-2">Move this shop forward so reminders, holds and waitlist offers actually run.</p>
                            <LearnMore>
                                <p>
                                    Appointments slide closer, reminders that were due go out, unpaid holds are let go, and
                                    waitlist offers that nobody claimed run out.
                                </p>
                                <p>It only moves your shop. The real date does not change, and no other salon is affected.</p>
                            </LearnMore>
                            <p v-if="outcome?.panel === 'skip'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                            <div class="mt-4 flex flex-wrap items-end gap-2">
                                <Button
                                    v-for="interval in intervals"
                                    :key="interval"
                                    :variant="interval === 'day' ? 'primary' : 'secondary'"
                                    :loading="skip.processing && skip.interval === interval"
                                    :disabled="busy"
                                    @click="skipAhead(interval)"
                                >
                                    {{ label(interval) }}
                                </Button>
                                <div class="min-w-40 flex-1">
                                    <TextInput
                                        v-model="jump.date"
                                        type="date"
                                        label="Jump to date"
                                        :disabled="busy"
                                    />
                                </div>
                                <Button
                                    variant="secondary"
                                    :loading="jump.processing"
                                    :disabled="busy || !jump.date"
                                    @click="jumpToDate"
                                >
                                    Jump to date
                                </Button>
                            </div>
                            <div class="mt-4 border-t border-rule pt-4">
                                <p class="text-13 text-ink-2">Send anything already due on the shop’s shifted clock.</p>
                                <p v-if="outcome?.panel === 'remind'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                                <div class="mt-3">
                                    <Button variant="secondary" :loading="remind.processing" :disabled="busy" @click="sendReminders">
                                        Send due reminders now
                                    </Button>
                                </div>
                            </div>
                        </Card>
                    </section>

                    <section class="space-y-4" aria-labelledby="sandbox-simulators">
                        <h2 id="sandbox-simulators" class="caption">Simulators</h2>
                        <Card>
                            <h3 class="text-15 text-ink">No-show simulator</h3>
                            <p class="mt-1 text-13 text-ink-2">Mark a booking missed the same way the diary does, without waiting for the hour.</p>
                            <LearnMore>
                                <p>Loyalty and the waitlist run the real pathway. Nothing is sent to a real phone.</p>
                            </LearnMore>
                            <p v-if="outcome?.panel === 'noshow'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                            <div class="mt-4 space-y-3">
                                <Select
                                    v-if="candidates.length"
                                    v-model="noShow.booking_id"
                                    label="Appointment"
                                    :disabled="busy"
                                    :options="candidates.map((row) => ({ value: row.id, label: row.label }))"
                                />
                                <p v-else class="text-13 text-ink-2">Load sample data to get appointments you can mark.</p>
                                <Button :loading="noShow.processing" :disabled="busy" @click="markNoShow">Mark as no-show now</Button>
                            </div>
                        </Card>
                        <Card>
                            <h3 class="text-15 text-ink">Waitlist simulator</h3>
                            <p class="mt-1 text-13 text-ink-2">Free a booked hour and offer it to the next person in line.</p>
                            <LearnMore>
                                <p>
                                    Uses the real SMS-offer flow. Expire an offer to watch it roll on. The texts land in
                                    the outbox, not on a phone.
                                </p>
                            </LearnMore>
                            <p v-if="outcome?.panel === 'waitlist'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                            <div class="mt-4 flex flex-wrap gap-2">
                                <Button :loading="waitFree.processing" :disabled="busy" @click="freeSlot">Free up a slot now</Button>
                                <Button variant="secondary" :loading="waitExpire.processing" :disabled="busy" @click="expireOffer">
                                    Expire current offer
                                </Button>
                            </div>
                        </Card>
                    </section>

                    <section class="space-y-4" aria-labelledby="sandbox-messages">
                        <h2 id="sandbox-messages" class="caption">Messages</h2>
                        <Card>
                            <h3 class="text-15 text-ink">SMS outbox</h3>
                            <p class="mt-1 text-13 text-ink-2">Every sandbox text is logged here instead of being sent. Names, not numbers.</p>
                            <LearnMore>
                                <p>Clearing the list does not touch appointments or customers.</p>
                            </LearnMore>
                            <p v-if="outcome?.panel === 'outbox'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                            <div class="mt-4">
                                <SmsOutbox :messages="outbox" />
                            </div>
                            <div class="mt-4">
                                <Button
                                    variant="danger"
                                    :loading="outboxClear.processing"
                                    :disabled="busy || outbox.length === 0"
                                    @click="confirmingOutbox = true"
                                >
                                    Clear outbox
                                </Button>
                            </div>
                        </Card>
                    </section>

                    <section class="space-y-4" aria-labelledby="sandbox-network">
                        <h2 id="sandbox-network" class="caption">Network</h2>
                        <Card>
                            <h3 class="text-15 text-ink">Flaky network</h3>
                            <p class="mt-1 text-13 text-ink-2">Diary booking and cancel may stall or fail at random. The public page is not affected.</p>
                            <p v-if="outcome?.panel === 'flaky'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                            <div class="mt-4">
                                <Toggle
                                    :model-value="flaky.enabled"
                                    label="Simulate flaky network"
                                    hint="Does not touch real customer bookings."
                                    :disabled="busy"
                                    @update:model-value="toggleFlaky"
                                />
                            </div>
                        </Card>
                    </section>
                </div>

                <div class="border-t border-rule pt-8 lg:col-span-2">
                    <Card>
                        <h3 class="text-15 text-ink">Start again</h3>
                        <p class="mt-1 text-13 text-ink-2">
                            Empty customers, appointments and the waitlist. Staff, services, hours and settings stay.
                        </p>
                        <p v-if="outcome?.panel === 'reset'" class="mt-3 text-13 text-ink">{{ outcome.message }}</p>
                        <div class="mt-4">
                            <Button variant="danger" :loading="reset.processing" :disabled="busy" @click="confirmingReset = true">
                                Reset my shop
                            </Button>
                        </div>
                    </Card>
                </div>
            </div>
        </div>

        <ConfirmDialog
            :show="confirmingSample"
            title="Replace what is in your shop?"
            :confirm-label="`Load ${chosenSize?.label ?? 'the sample shop'}`"
            cancel-label="Leave it as it is"
            :loading="sample.processing"
            @close="confirmingSample = false"
            @confirm="loadSample"
        >
            This clears the customers, appointments and waitlist you have now and puts a
            {{ chosenSize?.label?.toLowerCase() ?? 'sample shop' }} in their place. Your staff, services, opening hours
            and settings are not touched. It cannot be undone.
        </ConfirmDialog>

        <ConfirmDialog
            :show="confirmingOutbox"
            title="Clear the SMS outbox?"
            confirm-label="Clear outbox"
            cancel-label="Keep the log"
            :loading="outboxClear.processing"
            @close="confirmingOutbox = false"
            @confirm="clearOutbox"
        >
            This deletes the logged texts only. Appointments and customers stay where they are.
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
