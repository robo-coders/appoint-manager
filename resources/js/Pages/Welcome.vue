<script setup lang="ts">
import GuestLayout from '@/Layouts/GuestLayout.vue';
import { Head, Link, usePage } from '@inertiajs/vue3';

defineProps<{
    canLogin?: boolean;
    canRegister?: boolean;
}>();

const page = usePage();
</script>

<template>
    <Head title="Welcome" />

    <GuestLayout>
        <div class="space-y-4">
            <h1 class="font-display text-20 tracking-20 text-ink">
                {{ page.props.appName }}
            </h1>
            <p class="text-14 text-ink-2">
                Booking software for {{ page.props.vertical.label.toLowerCase() }} businesses.
            </p>
            <div v-if="canLogin" class="flex gap-4 text-14">
                <Link
                    v-if="page.props.auth.user"
                    :href="route('diary.index')"
                    class="underline decoration-rule underline-offset-4 hover:text-ink"
                >
                    Open your diary
                </Link>
                <template v-else>
                    <Link :href="route('login')" class="underline decoration-rule underline-offset-4 hover:text-ink">
                        Log in
                    </Link>
                    <Link
                        v-if="canRegister"
                        :href="route('register')"
                        class="underline decoration-rule underline-offset-4 hover:text-ink"
                    >
                        Create an account
                    </Link>
                </template>
            </div>
        </div>
    </GuestLayout>
</template>
