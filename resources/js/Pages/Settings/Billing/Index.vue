<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { computed, nextTick, onBeforeUnmount, ref } from 'vue';

export type BillingState =
    | 'healthy'
    | 'past_due'
    | 'unpaid'
    | 'trial'
    | 'trial_ended'
    | 'no_payment_method'
    | 'cancelled_pending'
    | 'cancelled_ended'
    | 'incomplete';

export type BillingPayload = {
    state: BillingState;
    plan: {
        name: string;
        interval: 'monthly' | 'yearly' | null;
        price: string;
        period: string;
        limits: string;
        renews_on: string | null;
        yearly_saving: string | null;
    };
    payment_method: {
        present: boolean;
        brand: string | null;
        last4: string | null;
        exp: string | null;
    };
    banner: {
        variant: BillingState;
        message: string;
        action_label: string | null;
        reassurance: boolean;
    } | null;
    ends_on: string | null;
    trial_days: number;
    invoices: Array<{
        id: number;
        invoice_number: string;
        date: string;
        amount: string;
        status: string;
        download_url: string;
        declined: boolean;
    }>;
    csv_url: string;
    can_charge: boolean;
    stripe_key: string | null;
};

const props = defineProps<{ billing: BillingPayload }>();

const swap = useForm({ interval: 'yearly' });
const cancel = useForm({});
const resume = useForm({});
const refresh = useForm({});
const checkout = useForm({});
const payment = useForm({ payment_method: '' });

const modal = ref(false);
const cancelConfirm = ref(false);
const cardOpen = ref(false);
const preview = ref<{
    old_price: string;
    new_price: string;
    statement: string;
    charge: string;
    charge_pence: number;
} | null>(null);
const previewError = ref('');
const previewLoading = ref(false);
const cardError = ref('');
const cardMounting = ref(false);

const yearly = computed(() => props.billing.plan.interval === 'yearly');
const ended = computed(() => props.billing.state === 'cancelled_ended');
const targetInterval = computed(() => (yearly.value ? 'monthly' : 'yearly'));
const targetName = computed(() => `${props.billing.plan.name} ${targetInterval.value === 'yearly' ? 'yearly' : 'monthly'}`);

const xsrf = () => {
    const match = document.cookie.match(/XSRF-TOKEN=([^;]+)/);

    return match ? decodeURIComponent(match[1]) : '';
};

const openChange = async () => {
    preview.value = null;
    previewError.value = '';
    previewLoading.value = true;
    modal.value = true;
    swap.interval = targetInterval.value;

    try {
        const response = await fetch(route('settings.billing.preview'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({ interval: targetInterval.value }),
        });
        const body = (await response.json()) as Record<string, unknown>;

        if (!response.ok) {
            previewError.value = String(body.error ?? 'We could not price this change. Try again in a moment.');
            preview.value = null;

            return;
        }

        preview.value = body as unknown as typeof preview.value;
    } catch {
        previewError.value = 'We could not price this change. Try again in a moment.';
        preview.value = null;
    } finally {
        previewLoading.value = false;
    }
};

const confirmSwap = () => {
    swap.post(route('settings.billing.swap'), {
        preserveScroll: true,
        only: ['billing'],
        onSuccess: () => {
            modal.value = false;
        },
    });
};

const confirmCancel = () => {
    cancel.post(route('settings.billing.cancel'), {
        preserveScroll: true,
        only: ['billing'],
        onSuccess: () => {
            cancelConfirm.value = false;
        },
    });
};

const confirmResume = () => {
    resume.post(route('settings.billing.resume'), { preserveScroll: true, only: ['billing'] });
};

const refreshStatus = () => {
    refresh.post(route('settings.billing.refresh'), { preserveScroll: true, only: ['billing'] });
};

const resubscribe = () => {
    checkout.post(route('settings.billing.checkout'));
};

const openCard = async () => {
    cardError.value = '';
    cardOpen.value = true;
    await nextTick();
    await mountCard();
};

const loadStripeJs = async () => {
    if ((window as unknown as { Stripe?: unknown }).Stripe) return;

    await new Promise<void>((resolve, reject) => {
        const script = document.createElement('script');
        script.src = 'https://js.stripe.com/v3/';
        script.async = true;
        script.onload = () => resolve();
        script.onerror = () => reject(new Error('Could not load payments.'));
        document.head.appendChild(script);
    });
};

const mountCard = async () => {
    if (!props.billing.stripe_key || !props.billing.can_charge) {
        cardError.value = 'Card payments are not set up on this installation yet.';

        return;
    }

    cardMounting.value = true;

    try {
        await loadStripeJs();
        const secretResponse = await fetch(route('settings.billing.setup-intent'), {
            method: 'POST',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-XSRF-TOKEN': xsrf(),
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: '{}',
        });
        const secretBody = (await secretResponse.json()) as { client_secret?: string; error?: string };

        if (!secretResponse.ok || !secretBody.client_secret) {
            cardError.value = secretBody.error ?? 'The card form could not start.';

            return;
        }

        const StripeCtor = (window as unknown as { Stripe?: (key: string) => { elements: () => { create: (t: string) => { mount: (el: string) => void } }; confirmCardSetup: Function } }).Stripe;
        if (!StripeCtor) return;

        const stripe = StripeCtor(props.billing.stripe_key);
        const card = stripe.elements().create('card');
        card.mount('#billing-card-element');
        (window as unknown as { __ddStripe: unknown; __ddCard: unknown; __ddSecret: string }).__ddStripe = stripe;
        (window as unknown as { __ddCard: unknown }).__ddCard = card;
        (window as unknown as { __ddSecret: string }).__ddSecret = secretBody.client_secret;
    } catch {
        cardError.value = 'Payments didn’t load. Check your connection and try again.';
    } finally {
        cardMounting.value = false;
    }
};

const saveCard = async () => {
    const stripe = (window as unknown as { __ddStripe?: { confirmCardSetup: Function } }).__ddStripe;
    const card = (window as unknown as { __ddCard?: unknown }).__ddCard;
    const secret = (window as unknown as { __ddSecret?: string }).__ddSecret;

    if (!stripe || !card || !secret) {
        cardError.value = 'The payment form is not ready.';

        return;
    }

    const result = await stripe.confirmCardSetup(secret, { payment_method: { card } });

    if (result.error) {
        cardError.value = result.error.message ?? 'That card could not be saved.';

        return;
    }

    payment.payment_method = result.setupIntent?.payment_method ?? '';
    payment.post(route('settings.billing.payment-method'), {
        preserveScroll: true,
        only: ['billing'],
        onSuccess: () => {
            cardOpen.value = false;
        },
    });
};

onBeforeUnmount(() => {
    delete (window as unknown as { __ddStripe?: unknown }).__ddStripe;
    delete (window as unknown as { __ddCard?: unknown }).__ddCard;
});

const onPeriod = (interval: 'monthly' | 'yearly') => {
    if (props.billing.plan.interval === interval) return;
    openChange();
};

const bannerAction = () => {
    if (props.billing.state === 'cancelled_ended') {
        resubscribe();

        return;
    }

    openCard();
};
</script>

<template>
    <AppLayout>
        <Head title="Billing" />
        <div class="mb-6">
            <p class="eyebrow">Screen 03 · settings</p>
            <h1 class="display-light mt-2 text-34">Billing</h1>
        </div>

        <SettingsNav current="billing" />

        <div class="mt-8 max-w-3xl">
            <div
                v-if="billing.banner"
                data-testid="billing-banner"
                :data-variant="billing.banner.variant"
                class="mb-6 flex flex-col gap-4 rounded border px-4 py-3 sm:flex-row sm:items-center"
                :class="
                    billing.banner.variant === 'past_due' || billing.banner.variant === 'unpaid'
                        ? 'border-accent'
                        : 'border-rule'
                "
            >
                <svg
                    v-if="billing.banner.variant === 'past_due'"
                    width="15"
                    height="15"
                    viewBox="0 0 16 16"
                    fill="none"
                    class="shrink-0 stroke-accent"
                    stroke-width="1.5"
                    aria-hidden="true"
                >
                    <path d="M8 1.5 15 14H1L8 1.5Z" />
                    <path d="M8 6v3.5" />
                    <path d="M8 11.4v.6" />
                </svg>
                <p class="flex-1 text-14">{{ billing.banner.message }}</p>
                <button
                    v-if="billing.banner.action_label && billing.can_charge"
                    type="button"
                    class="min-h-tap shrink-0 rounded bg-accent px-4 text-13 font-medium text-paper hover:bg-accent-strong"
                    @click="bannerAction"
                >
                    {{ billing.banner.action_label }}
                </button>
            </div>

            <section v-if="ended" class="rounded border border-rule px-6 py-6">
                <p class="eyebrow">Current plan</p>
                <h2 class="display-light mt-3 text-24">Resubscribe</h2>
                <p class="mt-3 text-14 text-ink-2">{{ billing.plan.limits }}</p>
                <button
                    type="button"
                    class="mt-6 min-h-tap rounded bg-accent px-4 text-14 font-medium text-paper hover:bg-accent-strong"
                    :disabled="checkout.processing || !billing.can_charge"
                    @click="resubscribe"
                >
                    Resubscribe · {{ billing.plan.price }} {{ billing.plan.period }}
                </button>
            </section>

            <section v-else class="rounded border border-rule px-6 py-6">
                <div class="flex flex-col gap-8 sm:flex-row sm:flex-wrap sm:items-start sm:justify-between">
                    <div>
                        <p class="eyebrow">Current plan</p>
                        <div class="mt-3 flex flex-wrap items-baseline gap-3">
                            <h2 class="display-light text-24">{{ billing.plan.name }}</h2>
                            <span
                                v-if="billing.plan.yearly_saving"
                                class="rounded border border-accent px-2 py-0.5 font-mono text-12 text-accent"
                            >
                                {{ billing.plan.yearly_saving }}
                            </span>
                        </div>
                        <div class="mt-2 flex items-baseline gap-2">
                            <span class="font-mono text-20">{{ billing.plan.price }}</span>
                            <span class="font-mono text-13 text-ink-2">{{ billing.plan.period }}</span>
                        </div>
                        <p class="mt-4 text-13 text-ink-2">
                            {{ billing.plan.limits }}<br />
                            <template v-if="billing.ends_on">Ends {{ billing.ends_on }}</template>
                            <template v-else-if="billing.plan.renews_on">Renews {{ billing.plan.renews_on }}</template>
                        </p>
                    </div>

                    <div class="flex flex-col gap-6">
                        <div>
                            <p class="eyebrow">Billing period</p>
                            <div class="mt-3 inline-flex overflow-hidden rounded border border-rule">
                                <button
                                    type="button"
                                    class="min-h-tap px-4 text-13 font-medium"
                                    :class="yearly ? 'bg-transparent text-ink-2' : 'bg-ink-tint text-ink'"
                                    @click="onPeriod('monthly')"
                                >
                                    Monthly
                                </button>
                                <button
                                    type="button"
                                    class="min-h-tap border-l border-rule px-4 text-13 font-medium"
                                    :class="yearly ? 'bg-ink-tint text-ink' : 'bg-transparent text-ink-2'"
                                    @click="onPeriod('yearly')"
                                >
                                    Yearly
                                </button>
                            </div>
                        </div>
                        <div>
                            <p class="eyebrow">Payment method</p>
                            <div v-if="billing.payment_method.present" class="mt-3 flex items-center gap-3">
                                <svg
                                    width="30"
                                    height="20"
                                    viewBox="0 0 30 20"
                                    fill="none"
                                    class="stroke-ink-2"
                                    stroke-width="1.5"
                                    aria-hidden="true"
                                >
                                    <rect x=".75" y=".75" width="28.5" height="18.5" rx="3" />
                                    <path d="M.75 6.5h28.5" />
                                    <path d="M4 14h6" />
                                </svg>
                                <span class="font-mono text-14">•••• {{ billing.payment_method.last4 }}</span>
                                <span v-if="billing.payment_method.exp" class="text-13 text-ink-2">
                                    exp {{ billing.payment_method.exp }}
                                </span>
                            </div>
                            <p v-else class="mt-3 text-14 text-ink-2">No payment method on file</p>
                        </div>
                    </div>

                    <div class="flex flex-col items-start gap-3">
                        <button
                            v-if="billing.can_charge && billing.plan.interval"
                            type="button"
                            class="min-h-tap rounded bg-accent px-4 text-14 font-medium text-paper hover:bg-accent-strong"
                            @click="openChange"
                        >
                            Change plan
                        </button>
                        <button
                            v-if="billing.can_charge"
                            type="button"
                            class="min-h-tap rounded bg-accent px-4 text-14 font-medium text-paper hover:bg-accent-strong"
                            @click="openCard"
                        >
                            {{ billing.payment_method.present ? 'Update payment method' : 'Add payment method' }}
                        </button>
                        <button
                            v-if="billing.state === 'cancelled_pending'"
                            type="button"
                            class="border-b border-rule text-13 text-ink-2 hover:text-ink"
                            :disabled="resume.processing"
                            @click="confirmResume"
                        >
                            Resume subscription
                        </button>
                        <button
                            v-else-if="billing.plan.interval"
                            type="button"
                            class="border-b border-rule text-13 text-ink-2 hover:text-ink"
                            @click="cancelConfirm = true"
                        >
                            Cancel subscription
                        </button>
                    </div>
                </div>
            </section>

            <p class="mt-4">
                <button type="button" class="border-b border-rule text-13 text-ink-2 hover:text-ink" @click="refreshStatus">
                    Refresh billing status
                </button>
            </p>

            <div class="mt-12 flex items-baseline justify-between gap-4">
                <h3 class="display-light text-20">Invoice history</h3>
                <a :href="billing.csv_url" class="border-b border-rule text-13 text-ink-2 hover:text-ink">
                    Download all as CSV
                </a>
            </div>

            <div class="mt-4 hidden border-t border-rule sm:block">
                <div class="eyebrow grid grid-cols-[150px_130px_1fr_110px] gap-4 border-b border-rule py-2">
                    <span>Date</span>
                    <span>Amount</span>
                    <span>Status</span>
                    <span class="text-right">Invoice</span>
                </div>
                <div
                    v-for="invoice in billing.invoices"
                    :key="invoice.id"
                    class="grid grid-cols-[150px_130px_1fr_110px] items-center gap-4 border-b border-rule py-3"
                >
                    <span class="font-mono text-13">{{ invoice.date }}</span>
                    <span class="font-mono text-13">{{ invoice.amount }}</span>
                    <span>
                        <span
                            class="inline-block rounded border px-2 py-0.5 text-12"
                            :class="invoice.declined ? 'border-accent text-accent' : 'border-rule text-ink-2'"
                        >
                            {{ invoice.status }}
                        </span>
                    </span>
                    <a :href="invoice.download_url" class="justify-self-end border-b border-rule text-13 text-ink-2 hover:text-ink">
                        PDF
                    </a>
                </div>
                <p v-if="billing.invoices.length === 0" class="py-6 text-14 text-ink-2">No invoices yet.</p>
            </div>

            <div class="mt-4 border-t border-rule sm:hidden">
                <article
                    v-for="invoice in billing.invoices"
                    :key="invoice.id"
                    class="border-b border-rule py-3"
                >
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <span class="font-mono text-13">{{ invoice.date }}</span>
                        <span class="font-mono text-13">{{ invoice.amount }}</span>
                        <span
                            class="inline-block rounded border px-2 py-0.5 text-12"
                            :class="invoice.declined ? 'border-accent text-accent' : 'border-rule text-ink-2'"
                        >
                            {{ invoice.status }}
                        </span>
                    </div>
                    <a :href="invoice.download_url" class="mt-2 inline-block border-b border-rule text-13 text-ink-2">PDF</a>
                </article>
                <p v-if="billing.invoices.length === 0" class="py-6 text-14 text-ink-2">No invoices yet.</p>
            </div>
        </div>

        <div
            v-if="modal"
            class="fixed inset-0 z-20 flex items-center justify-center bg-overlay p-6"
            role="dialog"
            aria-modal="true"
            aria-label="Change plan"
        >
            <div class="w-full max-w-sm rounded border border-rule bg-paper p-6">
                <p class="eyebrow">Change plan</p>
                <h2 class="display-light mt-3 text-24">{{ billing.plan.name }} → {{ targetName }}</h2>
                <div v-if="preview" class="mt-6 flex items-baseline gap-4 border-b border-rule pb-4">
                    <span class="font-mono text-14 text-ink-2 line-through">{{ preview.old_price }}</span>
                    <span class="font-mono text-20">{{ preview.new_price }}</span>
                </div>
                <p v-if="previewError" class="mt-4 text-14 text-danger">{{ previewError }}</p>
                <p v-else-if="previewLoading" class="mt-4 text-14 text-ink-2">Working out the price…</p>
                <p v-else-if="preview" class="mt-4 text-14 text-ink-2">{{ preview.statement }}</p>
                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <button
                        type="button"
                        class="min-h-tap rounded bg-accent px-4 text-14 font-medium text-paper hover:bg-accent-strong disabled:opacity-50"
                        :disabled="!preview || !!previewError || swap.processing"
                        @click="confirmSwap"
                    >
                        {{ preview ? `Confirm and pay ${preview.charge}` : 'Confirm' }}
                    </button>
                    <button type="button" class="border-b border-rule text-14 text-ink-2 hover:text-ink" @click="modal = false">
                        Keep {{ yearly ? 'yearly' : 'monthly' }}
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="cancelConfirm"
            class="fixed inset-0 z-20 flex items-center justify-center bg-overlay p-6"
            role="dialog"
            aria-modal="true"
            aria-label="Cancel subscription"
        >
            <div class="w-full max-w-sm rounded border border-rule bg-paper p-6">
                <p class="eyebrow">Cancel subscription</p>
                <h2 class="display-light mt-3 text-24">Cancel at the end of the period?</h2>
                <p class="mt-4 text-14 text-ink-2">
                    You keep full access until
                    {{ billing.plan.renews_on ?? 'the period ends' }}. Nothing is deleted.
                </p>
                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <button
                        type="button"
                        class="min-h-tap rounded bg-accent px-4 text-14 font-medium text-paper hover:bg-accent-strong"
                        :disabled="cancel.processing"
                        @click="confirmCancel"
                    >
                        Cancel subscription
                    </button>
                    <button type="button" class="border-b border-rule text-14 text-ink-2 hover:text-ink" @click="cancelConfirm = false">
                        Keep it
                    </button>
                </div>
            </div>
        </div>

        <div
            v-if="cardOpen"
            class="fixed inset-0 z-20 flex items-center justify-center bg-overlay p-6"
            role="dialog"
            aria-modal="true"
            aria-label="Payment method"
        >
            <div class="w-full max-w-sm rounded border border-rule bg-paper p-6">
                <p class="eyebrow">Payment method</p>
                <h2 class="display-light mt-3 text-24">
                    {{ billing.payment_method.present ? 'Update card' : 'Add a card' }}
                </h2>
                <div id="billing-card-element" class="mt-6 rounded border border-rule bg-paper px-3 py-3" />
                <p v-if="cardError" class="mt-3 text-13 text-danger">{{ cardError }}</p>
                <div class="mt-6 flex flex-wrap items-center gap-4">
                    <button
                        type="button"
                        class="min-h-tap rounded bg-accent px-4 text-14 font-medium text-paper hover:bg-accent-strong"
                        :disabled="cardMounting || payment.processing"
                        @click="saveCard"
                    >
                        Save card
                    </button>
                    <button type="button" class="border-b border-rule text-14 text-ink-2 hover:text-ink" @click="cardOpen = false">
                        Close
                    </button>
                </div>
            </div>
        </div>
    </AppLayout>
</template>
