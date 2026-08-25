<script setup lang="ts">
import axios from 'axios';
import { nextTick, ref } from 'vue';

const props = defineProps<{
    offer: {
        token: string;
        status: string;
        starts_at_local: string;
        service_name: string;
        expires_at: string;
        claimable: boolean;
    };
    needs_deposit: boolean;
    urls: { claim: string };
    stripePublishableKey: string | null;
}>();

const error = ref('');
const done = ref(false);
const paying = ref(false);
const submitting = ref(false);
const clientSecret = ref('');
const manageUrl = ref('');
const stripeAccount = ref('');

const loadStripeJs = async () => {
    if ((window as unknown as { Stripe?: unknown }).Stripe) {
        return;
    }

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
    try {
        await loadStripeJs();
    } catch {
        error.value = 'Payments didn’t load. Check your connection and try again.';
        return;
    }
    const StripeCtor = (window as unknown as { Stripe?: (key: string, opts?: { stripeAccount?: string }) => any }).Stripe;
    if (!StripeCtor || !props.stripePublishableKey) {
        return;
    }
    const stripe = StripeCtor(props.stripePublishableKey, { stripeAccount: stripeAccount.value || undefined });
    const card = stripe.elements().create('card');
    card.mount('#offer-card-element');
    (window as unknown as { __kestrelCard: unknown; __kestrelStripe: unknown }).__kestrelCard = card;
    (window as unknown as { __kestrelStripe: unknown }).__kestrelStripe = stripe;
};

const confirmPay = async () => {
    paying.value = true;
    const stripe = (window as unknown as { __kestrelStripe?: { confirmCardPayment: Function } }).__kestrelStripe;
    const card = (window as unknown as { __kestrelCard?: unknown }).__kestrelCard;
    if (!stripe || !card) {
        error.value = 'Payment form is not ready.';
        paying.value = false;
        return;
    }
    const result = await stripe.confirmCardPayment(clientSecret.value, { payment_method: { card } });
    if (result.error) {
        error.value = result.error.message ?? 'Payment failed.';
        paying.value = false;
        return;
    }
    window.location.href = manageUrl.value;
};

const claim = async () => {
    submitting.value = true;
    error.value = '';
    try {
        const { data } = await axios.post(props.urls.claim);
        manageUrl.value = data.booking.manage_url;
        if (data.payment?.client_secret) {
            clientSecret.value = data.payment.client_secret;
            stripeAccount.value = data.payment.connected_account;
            done.value = true;
            await nextTick();
            mountCard();
            return;
        }
        window.location.href = data.booking.manage_url;
    } catch (err: unknown) {
        const status = axios.isAxiosError(err) ? err.response?.status : 0;
        if (status === 409 || status === 410) {
            error.value = axios.isAxiosError(err) ? String(err.response?.data?.message ?? 'Sorry, just taken.') : 'Sorry, just taken.';
        } else {
            error.value = 'Could not claim this slot.';
        }
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div class="space-y-4">
        <h1 class="text-20 font-medium">{{ offer.service_name }}</h1>
        <p class="text-14">{{ offer.starts_at_local }}</p>
        <p v-if="error" class="text-14 text-danger">{{ error }}</p>
        <button
            v-if="offer.claimable && !done"
            type="button"
            class="w-full rounded border border-ink bg-ink px-3 py-2 text-14 text-white disabled:opacity-50"
            :disabled="submitting"
            @click="claim"
        >
            Claim this slot
        </button>
        <div v-else-if="clientSecret" class="space-y-3">
            <p class="text-14">Pay the deposit to hold this slot. Confirmation happens after payment succeeds.</p>
            <div id="offer-card-element" class="rounded border border-rule bg-white p-3"></div>
            <button type="button" class="w-full rounded border border-ink bg-ink px-3 py-2 text-14 text-white" :disabled="paying" @click="confirmPay">
                Pay now
            </button>
        </div>
        <p v-else-if="!offer.claimable" class="text-14 text-ink-2">This offer is no longer available.</p>
    </div>
</template>
