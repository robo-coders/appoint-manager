<script setup lang="ts">
import EditorialAuthLayout from '@/Layouts/EditorialAuthLayout.vue';
import EditorialButton from '@/Components/ui/EditorialButton.vue';
import EditorialField from '@/Components/ui/EditorialField.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

/**
 * The console's own door.
 *
 * Drawn from `.design/mockups/login/admin-login.png` and its source at
 * `.design/mockups/frontend/admin-login.html`, on the same editorial shell as
 * the operator door — see `EditorialAuthLayout` — and departing from the
 * artboard in four places, each of them a thing the artboard has that this
 * product does not.
 *
 * ── What the artboard asked for and does not get ──────────────────────────
 *
 *   **No SSO.** The artboard leads with "Continue with staff SSO" and demotes
 *   email and password to a break-glass fallback behind a "SSO REQUIRED" pill.
 *   There is no SSO in this codebase — no Socialite, no SAML, no OIDC, one
 *   session guard — so the fallback is the only door there has ever been, and
 *   it is drawn here as the primary and only control. The pill and the button
 *   are gone rather than disabled, and no space is held for them: a greyed
 *   control on a door is a promise that somebody will file a bug against. Its
 *   "SSO rejected" state went with it.
 *
 *   **No "BREAK-GLASS SIGN-IN" label.** It named the form as an exception to
 *   something. With the SSO button gone there is nothing for it to be an
 *   exception to, so the card is headed "Admin sign in" and nothing else.
 *
 *   **No session history.** The artboard's LAST SESSION / DEVICE / FAILED
 *   ATTEMPTS block is not built: nothing records any of the three, and a panel
 *   of invented reassurance on the one page that guards every tenant's diary
 *   is worse than no panel. The platform table's ops footer — session length,
 *   audit logging, the on-call rota — is absent for the same reason.
 *
 *   **No self-serve reset link.** Recovery here is a ticket, which the
 *   artboard says too; what replaces its dead "raise a ticket with IT" link is
 *   the one line the "not a staff account" state was for, said without needing
 *   a failed attempt to say it.
 *
 * ── What did not change ───────────────────────────────────────────────────
 *
 * `admin-login` allows three attempts a minute against the operator side's
 * five, so the lockout arrives sooner and matters more — it is the state this
 * page is most likely to be read in at 2am. Ink, not clay: a hard stop is not
 * a typo.
 *
 * What is deliberately NOT distinguished is a real staff account with the
 * wrong password from a salon owner who found this hostname.
 * `AdminSessionController` returns `auth.failed` for both, so that this
 * surface never confirms an account exists. The artboard's "this isn't a
 * DiaryDesk staff address" is therefore not a state this page can be in, and
 * the artboard's specimen band that drew it — design documentation, rendered
 * below the fold as live content — is gone. See `EditorialAuthLayout`.
 */
const form = useForm({ email: '', password: '' });
const page = usePage();

/** A surface by host rather than by scheme-and-path. */
const hostOf = (url: string) => {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
};

const appHost = computed(() => hostOf(page.props.urls.app));
const adminHost = computed(() => hostOf(page.props.urls.admin));

/*
 * Staff addresses are on the bare domain, not on the console's own hostname —
 * `j.arnott@diarydesk.com`, never `@admin.diarydesk.com`. The marketing
 * surface is the one URL in the shared props that carries the bare domain, so
 * the placeholder reads it from there rather than hardcoding a domain that a
 * rename would leave behind.
 */
const mailDomain = computed(() => hostOf(page.props.urls.marketing));

const attemptFailed = computed(() => (form.errors.email?.includes('credentials') ? form.errors.email : ''));
const lockedOut = computed(() => (/too many/i.test(form.errors.email ?? '') ? form.errors.email : ''));
const emailError = computed(() => (attemptFailed.value || lockedOut.value ? '' : form.errors.email));
const expired = computed(() => (page.props.authNotice?.kind === 'expired' ? page.props.authNotice : null));
const incomplete = ref('');

onMounted(() => {
    const stop = router.on('exception', (event) => {
        incomplete.value = 'That sign-in did not complete. Refresh the page and try again.';
        event.preventDefault();
    });

    onUnmounted(stop);
});

const emailField = ref<InstanceType<typeof EditorialField> | null>(null);
const passwordField = ref<InstanceType<typeof EditorialField> | null>(null);

/*
 * `novalidate`, for the reason set out at length on the operator door: the
 * browser's own "Please fill in this field" tooltip is a second, unstyleable
 * design system answering for this page, and on the console it would be the
 * first thing an admin sees at 2am. Turning it off means this page owes every
 * check the constraint API would have made — below — before the request goes.
 * The `required` and `type="email"` attributes stay for the accessibility tree
 * and the password manager; they no longer draw.
 */
const validate = () => {
    form.clearErrors();

    if (!form.email.trim()) {
        form.setError('email', 'Enter your work email.');
    } else if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(form.email.trim())) {
        form.setError('email', "This doesn't look like a full email address.");
    }

    if (!form.password) {
        form.setError('password', 'Enter your password.');
    }

    if (form.errors.email) {
        emailField.value?.focus();
    } else if (form.errors.password) {
        passwordField.value?.focus();
    }

    return !form.hasErrors;
};

const submit = () => {
    incomplete.value = '';

    /*
     * Worth more here than on the operator side: `admin-login` allows three
     * attempts a minute, and a submit the browser could have stopped should
     * never be one of them.
     */
    if (!validate()) {
        return;
    }

    form.post(route('admin.login.store'), { onFinish: () => form.reset('password') });
};
</script>

<template>
    <EditorialAuthLayout tag="INTERNAL">
        <Head title="Console" />

        <!--
            Runbooks and Status are internal destinations this codebase does
            not host. Set as text rather than as links to nothing, the same way
            the operator door handles its status page and help centre. The
            artboard's third item was the "SSO REQUIRED" pill and it is gone.
        -->
        <template #nav>
            <span>Runbooks</span>
            <span>Status</span>
        </template>

        <template #lede>
            <p class="ed-kicker">PLATFORM CONTROL</p>
            <h1 class="ed-display ed-display--console">Every shop's diary sits behind this door.</h1>
            <p class="ed-sub">
                Tenant accounts, billing, releases and support access. Sign in with your
                {{ $page.props.appName }} staff account — every action is logged against your name.
            </p>
        </template>

        <template #panel>
            <div class="ed-panel">
                <div class="ed-panel-head">
                    <span class="ed-panel-title">Platform, right now</span>
                    <span class="ed-panel-note ed-panel-note--quiet">EU-WEST-2</span>
                </div>
                <div class="ed-cells">
                    <span class="ed-cell">Tenant accounts</span>
                    <span class="ed-cell">Billing &amp; plans</span>
                    <span class="ed-cell">Releases &amp; flags</span>
                    <span class="ed-cell">
                        <span class="ed-dot" aria-hidden="true" />
                        Support access
                    </span>
                </div>
            </div>
        </template>

        <template #card>
            <div class="ed-card">
                <h2 class="ed-card-title">Admin sign in</h2>
                <p class="ed-card-sub">
                    Staff accounts only. Provisioned by {{ $page.props.appName }} IT.
                </p>

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

                <form class="ed-form" novalidate @submit.prevent="submit">
                    <EditorialField
                        ref="emailField"
                        v-model="form.email"
                        type="email"
                        label="Work email"
                        :placeholder="`name@${mailDomain}`"
                        :error="emailError"
                        autocomplete="username"
                        required
                        autofocus
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
                    />

                    <EditorialButton type="submit" variant="outline" block :loading="form.processing">
                        Sign in
                    </EditorialButton>
                </form>

                <!--
                    The one thing the artboard's "not a staff account" state was
                    for, said without needing a failed attempt to say it.
                    Static, so it reveals nothing about any address typed into
                    the form above.
                -->
                <p class="ed-card-note">
                    Lost credentials or a lost device are reset internally — raise a ticket with
                    IT. Running a salon rather than the platform? Sign in at
                    <a :href="page.props.urls.app" class="ed-link">{{ appHost }}</a>.
                </p>
            </div>
        </template>

        <template #foot>{{ adminHost }} · authorised {{ $page.props.appName }} staff only</template>

        <!-- All four are internal destinations this codebase does not host. -->
        <template #foot-nav>
            <span>Status</span>
            <span>Runbooks</span>
            <span>Access policy</span>
            <span>IT support</span>
        </template>
    </EditorialAuthLayout>
</template>
