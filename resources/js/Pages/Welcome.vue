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

    <GuestLayout :title="page.props.appName" lede="Appointment software for businesses that lose money when people do not turn up.">
        <div>
            <div v-if="canLogin" class="flex flex-wrap gap-4 text-14">
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
