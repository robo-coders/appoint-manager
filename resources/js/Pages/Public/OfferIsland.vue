<script setup lang="ts">
import ProposalHeading from '@/Components/Public/ProposalHeading.vue';
import Button from '@/Components/ui/Button.vue';
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';
import Countdown from '@/Components/ui/Countdown.vue';
import axios from 'axios';
import { nextTick, ref } from 'vue';

/**
 * A freed appointment, offered to somebody on the waitlist.
 *
 * The same page as the booking page, wearing a different hat: the same 34px
 * statement of one finished appointment, the same single column, the same
 * primary button. It used to be a 20px heading, a bare time and a black button
 * with no relationship to anything else the customer had seen.
 *
 * Two things are specific to this screen.
 *
 * **The countdown is live.** The offer expires, several people were texted, and
 * a static "expires at 15:40" gives a customer no reason to decide now — which
 * is the entire mechanic. `Countdown` ticks in mono so the digits do not
 * shuffle, and it is the second-most prominent thing here after the
 * appointment itself.
 *
 * **Taken is a designed state, not an error.** Somebody else was faster; that
 * is the system working, not a fault. So it is the same layout with the same
 * dominant type, showing the *next* appointment already proposed, and one
 * button. A red alert box would tell a customer they had done something wrong.
 */

const props = defineProps<{
    offer: {
        token: string;
        status: string;
        starts_at: string;
        day_label: string;
        weekday: string;
        time: string;
        service_name: string;
        expires_at: string | null;
        claimable: boolean;
        context: string;
        cost_line: string;
    };
    /** The suggester's answer for this customer, used only if the slot is taken. */
    fallback: {
        day_label: string;
        weekday: string;
        time: string;
        context: string;
        cost_line: string;
        reason: string;
        meta: string;
        url: string;
    } | null;
    needs_deposit: boolean;
    urls: { claim: string; book: string };
    stripePublishableKey: string | null;
}>();

const error = ref('');
const taken = ref(!props.offer.claimable);
const expired = ref(false);
const submitting = ref(false);
const paying = ref(false);
const clientSecret = ref('');
const stripeAccount = ref('');
const manageUrl = ref('');

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
    try {
        await loadStripeJs();
    } catch {
        error.value = 'Payments didn’t load. Check your connection and try again.';

        return;
    }

    const StripeCtor = (window as unknown as { Stripe?: (key: string, opts?: { stripeAccount?: string }) => any }).Stripe;
    if (!StripeCtor || !props.stripePublishableKey) return;

    const stripe = StripeCtor(props.stripePublishableKey, { stripeAccount: stripeAccount.value || undefined });
    const card = stripe.elements().create('card');
    card.mount('#offer-card-element');
    (window as unknown as { __amCard: unknown; __amStripe: unknown }).__amCard = card;
    (window as unknown as { __amStripe: unknown }).__amStripe = stripe;
};

const confirmPay = async () => {
    paying.value = true;
    error.value = '';

    const stripe = (window as unknown as { __amStripe?: { confirmCardPayment: Function } }).__amStripe;
    const card = (window as unknown as { __amCard?: unknown }).__amCard;

    if (!stripe || !card) {
        error.value = 'The payment form is not ready.';
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

/** The salon's own booking page, for a customer whose offer has gone. */
const goBook = () => {
    window.location.href = props.urls.book;
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
            await nextTick();
            await mountCard();

            return;
        }

        window.location.href = data.booking.manage_url;
    } catch (err: unknown) {
        const status = axios.isAxiosError(err) ? err.response?.status : 0;

        // 409 someone else claimed it, 410 the offer ran out. Both are the same
        // fact for a customer: this appointment is not available any more.
        if (status === 409 || status === 410) {
            taken.value = true;
        } else {
            error.value = 'We couldn’t claim this appointment. Please try again.';
        }
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <div>
        <p v-if="error" class="mb-4 text-15 text-danger" role="alert">{{ error }}</p>

        <!-- ============================================================
             Taken. Designed, not an error: same layout, same 34px, and the
             next appointment already proposed.
             ============================================================ -->
        <section v-if="taken">
            <template v-if="fallback">
                <ProposalHeading
                    :context="fallback.context"
                    :day-label="fallback.day_label"
                    :time="fallback.time"
                    :cost-line="fallback.cost_line"
                    level="h1"
                />

                <p class="mt-4 text-15 text-ink-2">
                    Somebody else took {{ offer.day_label }} at
                    <span class="font-mono">{{ offer.time }}</span> first. This is the next one we can do.
                </p>

                <div class="mt-6">
                    <Button variant="brand" block :href="fallback.url">
                        Book {{ fallback.weekday }} at <span class="font-mono">{{ fallback.time }}</span>
                    </Button>
                </div>

                <p class="mt-3 text-center text-13 text-ink-2">
                    You are still on the waitlist. We will keep texting you when things open up.
                </p>
            </template>

            <template v-else>
                <h1 class="text-34 font-medium">Just gone</h1>
                <p class="mt-3 text-15 text-ink-2">
                    Somebody else took {{ offer.day_label }} at
                    <span class="font-mono">{{ offer.time }}</span
                    >. You are still on the waitlist, and there is nothing else free right now.
                </p>
                <ul class="mt-6">
                    <li>
                        <ChoiceRow label="See what the diary looks like" meta="Book" @pick="goBook" />
                    </li>
                </ul>
            </template>
        </section>

        <!-- ============================================================
             Pay the deposit — the last step before the slot is really held.
             ============================================================ -->
        <section v-else-if="clientSecret" class="space-y-4">
            <h1 class="text-20 font-medium">Pay the deposit</h1>
            <p class="text-15 text-ink-2">{{ offer.cost_line }}. Confirmation happens once the payment succeeds.</p>
            <div id="offer-card-element" class="rounded border border-rule bg-white p-3"></div>
            <Button variant="brand" block :loading="paying" @click="confirmPay">Pay now</Button>
        </section>

        <!-- ============================================================
             The offer.
             ============================================================ -->
        <section v-else>
            <ProposalHeading
                :context="offer.context"
                :day-label="offer.day_label"
                :time="offer.time"
                :cost-line="offer.cost_line"
                level="h1"
            />

            <!-- The clock. Second only to the appointment itself, because the
                 whole mechanic is that it runs out. -->
            <p v-if="offer.expires_at && !expired" class="mt-4 text-17">
                Yours for
                <Countdown :expires-at="offer.expires_at" class="text-17" @expired="expired = true" />
                — we texted a few people about this one.
            </p>
            <p v-else-if="expired" class="mt-4 text-15 text-ink-2">
                This offer has run out. You are still on the waitlist.
            </p>

            <div class="mt-6">
                <!-- Weekday and time, not the whole date: the date is in 34px
                     three lines above, and a button that restates it is a button
                     that has stopped naming an outcome and started narrating. -->
                <Button variant="brand" block :loading="submitting" :disabled="expired" @click="claim">
                    Take {{ offer.weekday }} at <span class="font-mono">{{ offer.time }}</span>
                </Button>
            </div>

            <p class="mt-3 text-center text-13 text-ink-2">
                Nothing is charged until you press this.
            </p>
        </section>
    </div>
</template>
