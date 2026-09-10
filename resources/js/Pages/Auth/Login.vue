<script setup lang="ts">
import AuthDiaryPreview from '@/Components/AuthDiaryPreview.vue';
import EditorialAuthLayout from '@/Layouts/EditorialAuthLayout.vue';
import EditorialButton from '@/Components/ui/EditorialButton.vue';
import EditorialCheckbox from '@/Components/ui/EditorialCheckbox.vue';
import EditorialField from '@/Components/ui/EditorialField.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * The operator's door.
 *
 * Drawn from `.design/mockups/login/operator-login.png` and its source at
 * `.design/mockups/frontend/operator-login.html`. Those are the binding
 * target and this page is a transcription of them: masthead, "WELCOME BACK",
 * the display line, a worked example of the diary, the card and the footer.
 * Not the artboard's second half — the "ERROR STATES" specimen band, all four
 * failures drawn at once — which is design documentation and was rendering as
 * live page content. See the note in `EditorialAuthLayout`.
 *
 * ── What changed, and why the app design system is not on this page ───────
 *
 * It was `GuestLayout` + `ui/TextInput` + `ui/Button`, which is the operator
 * app's system, and on this page that system deleted the page: no kicker, no
 * display headline, no diary card at desktop width, no footer navigation, and
 * a 24px h1 where the artboard has a 60px one. What was left was a form on a
 * flat sheet — correct, and not the screen that was designed.
 *
 * It is on the editorial token system now, which is the marketing site's and
 * is a sanctioned second system rather than a new one: same file, same values,
 * already exempt from `check:design`. `EditorialAuthLayout` carries the shell
 * and the reasoning; `resources/css/auth-editorial.css` carries the
 * appearance.
 *
 * ── What did not change ───────────────────────────────────────────────────
 *
 * The four failure states and the tone of each. Laravel reports both
 * "these credentials do not match" and "too many login attempts" on the
 * `email` key and they are not the same event, so both are lifted out of the
 * field. Nothing blocks paste — WCAG 2.2 AA 3.3.8 — and no route, request or
 * validation rule was touched.
 */
/*
 * `AuthenticatedSessionController` also sends `trialInvitation` — "It takes
 * about five minutes, and the first 30 days are free." It is not declared and
 * not rendered: it was a `GuestLayout` foot note, and the artboard's card ends
 * at the price. The prop wants deleting from the controller the next time that
 * file is open for a reason of its own.
 */
defineProps<{
    canResetPassword?: boolean;
    status?: string;
}>();

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const page = usePage();

const marketing = computed(() => page.props.urls.marketing.replace(/\/$/, ''));
const year = new Date().getFullYear();

/*
 * ── The four states this form can fail in ─────────────────────────────────
 *
 * The artboard's second half specifies the tone of each, and the tones are
 * not decoration: they say whose problem it is.
 *
 *   wrong password  accent   — clay. She can fix this by typing again.
 *   locked out      ink      — the artboard's "hard stop uses ink, not
 *                              terracotta". Typing again will not help, and
 *                              painting it the same colour as a typo invites
 *                              a fourth attempt.
 *   connection lost neutral  — nothing she did was wrong, so nothing on the
 *                              screen should look like an error.
 */
const attemptFailed = computed(() => (form.errors.email?.includes('credentials') ? form.errors.email : ''));
const lockedOut = computed(() => (/too many/i.test(form.errors.email ?? '') ? form.errors.email : ''));
const emailError = computed(() => (attemptFailed.value || lockedOut.value ? '' : form.errors.email));
const expired = computed(() => (page.props.authNotice?.kind === 'expired' ? page.props.authNotice : null));
const incomplete = ref('');

onMounted(() => {
    /*
     * Cross-origin / network failure. The host-mismatch bug used to die here:
     * Inertia never got a response, `onFinish` cleared the password, and the
     * form sat still. Name it, so it cannot be silent again.
     */
    const stop = router.on('exception', (event) => {
        incomplete.value = 'That sign-in did not complete. Refresh the page and try again.';
        event.preventDefault();
    });

    onUnmounted(stop);
});

const emailField = ref<InstanceType<typeof EditorialField> | null>(null);
const passwordField = ref<InstanceType<typeof EditorialField> | null>(null);

/*
 * ── Why the form is `novalidate` ──────────────────────────────────────────
 *
 * The inputs are `required` and one of them is `type="email"`, so an empty
 * submit used to be answered by the browser: a grey system tooltip reading
 * "Please fill in this field", in the OS font, anchored to the input, gone on
 * the next click. On a page drawn to say a failure in clay and ink under the
 * field it is not a near miss — it is a different design system arriving
 * uninvited, unstyleable, unreadable to the `role="alert"` region above, and
 * worded by the browser vendor.
 *
 * `novalidate` turns that off and takes on the obligation that came with it:
 * everything the constraint API would have caught is caught here instead,
 * before the request, in this page's own voice. The attributes stay — they are
 * what tells a screen reader the field is required, and what a password
 * manager reads — they simply no longer draw anything.
 */
const validate = () => {
    form.clearErrors();

    if (!form.email.trim()) {
        form.setError('email', 'Enter your email address.');
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
        // The artboard's wording, and deliberately not "invalid email": she is
        // most likely one `.co.uk` short of a whole address.
        form.setError('email', "This doesn't look like a full email address.");
    }

    if (!form.password) {
        form.setError('password', 'Enter your password.');
    }

    /*
     * Focus follows the message. Without this the page has said something the
     * person may be scrolled past, on a form whose caret is still wherever
     * they left it.
     */
    if (form.errors.email) {
        emailField.value?.focus();
    } else if (form.errors.password) {
        passwordField.value?.focus();
    }

    return !form.hasErrors;
};

const submit = () => {
    incomplete.value = '';

    // Client-side first, so an empty form never costs a round trip or an
    // attempt against the limiter. Anything the browser cannot know — whether
    // the password is right, whether the account is locked — is still the
    // server's answer, arriving on `form.errors` the same way.
    if (!validate()) {
        return;
    }

    form.post(route('login'), {
        onFinish: () => {
            form.reset('password');
        },
    });
};
</script>

<template>
    <EditorialAuthLayout :home-href="marketing">
        <Head title="Log in" />

        <template #nav>
            <a :href="`${marketing}/how-it-works`">How it works</a>
            <a :href="`${marketing}/pricing`">Pricing</a>
            <Link :href="route('register')" class="ed-nav-pill">Start free trial</Link>
        </template>

        <template #lede>
            <p class="ed-kicker">WELCOME BACK</p>
            <h1 class="ed-display">Your diary kept working while you were out.</h1>
            <p class="ed-sub">
                Two cancellations went to the waitlist and came back filled. Deposits are held,
                reminders are out.
            </p>
        </template>

        <template #panel>
            <AuthDiaryPreview />
        </template>

        <template #card>
            <div class="ed-card">
                <h2 class="ed-card-title">Log in</h2>
                <p class="ed-card-sub">Use the email your diary is set up with.</p>

                <!--
                    Never a blank form. A wrong password, a locked account, a
                    stale CSRF token and a request that never came back used to
                    look identical: password cleared, page unmoved, nothing
                    said. A groomer reads that as "the app is broken".

                    Ordered by how final they are. Locked outranks
                    wrong-password because once the limiter has tripped, the
                    password is no longer the thing standing between her and
                    the diary.
                -->
                <p v-if="expired" class="ed-alert ed-alert--accent" role="alert">
                    <span class="ed-alert-dot" aria-hidden="true" />
                    <span>{{ expired.title }} {{ expired.body }}</span>
                </p>
                <p v-else-if="lockedOut" class="ed-alert ed-alert--ink" role="alert">
                    <span class="ed-alert-dot" aria-hidden="true" />
                    <span>{{ lockedOut }}</span>
                </p>
                <p v-else-if="attemptFailed" class="ed-alert ed-alert--accent" role="alert">
                    <span class="ed-alert-dot" aria-hidden="true" />
                    <span>{{ attemptFailed }}</span>
                </p>
                <p v-else-if="incomplete" class="ed-alert ed-alert--neutral" role="alert">
                    <span class="ed-alert-dot" aria-hidden="true" />
                    <span>{{ incomplete }}</span>
                </p>
                <!-- Success, not failure: "your password has been reset" lands
                     here, so it is neutral rather than clay. -->
                <p v-else-if="status" class="ed-alert ed-alert--neutral" role="status">
                    <span class="ed-alert-dot" aria-hidden="true" />
                    <span>{{ status }}</span>
                </p>

                <form class="ed-form" novalidate @submit.prevent="submit">
                    <EditorialField
                        ref="emailField"
                        v-model="form.email"
                        type="email"
                        label="Email"
                        placeholder="you@yourshop.co.uk"
                        :error="emailError"
                        autocomplete="username"
                        required
                    />

                    <EditorialField
                        ref="passwordField"
                        v-model="form.password"
                        type="password"
                        label="Password"
                        placeholder="••••••••••"
                        :error="form.errors.password"
                        autocomplete="current-password"
                        required
                        reveal
                    >
                        <template #action>
                            <Link
                                v-if="canResetPassword"
                                :href="route('password.request')"
                                class="ed-field-link"
                            >
                                Forgot password?
                            </Link>
                        </template>
                    </EditorialField>

                    <EditorialCheckbox v-model="form.remember" label="Stay signed in on this computer" />

                    <EditorialButton type="submit" block :loading="form.processing">Log in</EditorialButton>
                </form>

                <div class="ed-card-foot">
                    <span>
                        No account yet?
                        <Link :href="route('register')" class="ed-link">Start free trial</Link>
                    </span>
                    <!--
                        The artboard prints the list price here, and this is a
                        second copy of it: `config('billing.monthly_price_pence')`
                        is the first, and `MarketingFigures::monthlyBare()` is
                        how every other surface reads it. Vue cannot reach
                        either, and this pass was scoped not to touch
                        `AuthenticatedSessionController`. It wants to arrive as
                        a prop the moment that file is open for another reason.
                    -->
                    <span class="ed-price">£29/mo</span>
                </div>
            </div>
        </template>

        <template #foot>© {{ year }} {{ $page.props.appName }}</template>

        <!--
            Two of the artboard's four have no page in this codebase — there is
            no status page and no help centre, and a footer link to nothing is
            worse than a word. They are set as text until there is somewhere
            for them to go; Privacy and Terms are real.
        -->
        <template #foot-nav>
            <span>Status</span>
            <span>Help centre</span>
            <a :href="`${marketing}/privacy`">Privacy</a>
            <a :href="`${marketing}/terms`">Terms</a>
        </template>
    </EditorialAuthLayout>
</template>
