<script setup lang="ts">
import AuthDiaryPreview from '@/Components/AuthDiaryPreview.vue';
import EditorialAuthLayout from '@/Layouts/EditorialAuthLayout.vue';
import EditorialButton from '@/Components/ui/EditorialButton.vue';
import EditorialCheckbox from '@/Components/ui/EditorialCheckbox.vue';
import EditorialField from '@/Components/ui/EditorialField.vue';
import { Head, Link, router, useForm, usePage } from '@inertiajs/vue3';
import { computed, onMounted, onUnmounted, ref } from 'vue';

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
        form.setError('email', 'Enter your email address.');
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
                    <span class="ed-price">£29/mo</span>
                </div>
            </div>
        </template>

        <template #foot>© {{ year }} {{ $page.props.appName }}</template>

        <template #foot-nav>
            <span>Status</span>
            <span>Help centre</span>
            <a :href="`${marketing}/privacy`">Privacy</a>
            <a :href="`${marketing}/terms`">Terms</a>
        </template>
    </EditorialAuthLayout>
</template>
