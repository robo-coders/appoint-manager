<script setup lang="ts">
import EditorialAuthLayout from '@/Layouts/EditorialAuthLayout.vue';
import EditorialButton from '@/Components/ui/EditorialButton.vue';
import EditorialField from '@/Components/ui/EditorialField.vue';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

const form = useForm({ email: '', password: '' });
const page = usePage();

const hostOf = (url: string) => {
    try {
        return new URL(url).host;
    } catch {
        return url;
    }
};

const appHost = computed(() => hostOf(page.props.urls.app));
const adminHost = computed(() => hostOf(page.props.urls.admin));

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

    if (!validate()) {
        return;
    }

    form.post(route('admin.login.store'), { onFinish: () => form.reset('password') });
};
</script>

<template>
    <EditorialAuthLayout tag="INTERNAL">
        <Head title="Console" />

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

                <p class="ed-card-note">
                    Lost credentials or a lost device are reset internally — raise a ticket with
                    IT. Running a salon rather than the platform? Sign in at
                    <a :href="page.props.urls.app" class="ed-link">{{ appHost }}</a>.
                </p>
            </div>
        </template>

        <template #foot>{{ adminHost }} · authorised {{ $page.props.appName }} staff only</template>

        <template #foot-nav>
            <span>Status</span>
            <span>Runbooks</span>
            <span>Access policy</span>
            <span>IT support</span>
        </template>
    </EditorialAuthLayout>
</template>
