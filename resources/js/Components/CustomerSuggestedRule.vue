<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import { router } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    customerId: number;
    rule: { no_show_count: number; window_months: number; message: string };
}>();

const FALLBACK_ERROR = 'That did not go through. The rule is unchanged — try again.';

const dismissed = ref(false);
const failure = ref<string | null>(null);
const working = ref(false);

const send = (url: string) => {
    dismissed.value = true;
    failure.value = null;
    working.value = true;

    router.post(
        url,
        {},
        {
            preserveScroll: true,
            onError: (errors: Record<string, string>) => {
                dismissed.value = false;
                failure.value = Object.values(errors)[0] ?? FALLBACK_ERROR;
            },
            onFinish: () => {
                working.value = false;
            },
        },
    );
};

const requireFullPayment = () => send(route('customers.require-full-payment', props.customerId));

const dismiss = () => send(route('customers.dismiss-rule', props.customerId));
</script>

<template>
    <section
        v-if="!dismissed"
        class="rounded border border-accent-rule bg-accent-tint p-4"
        data-testid="suggested-rule"
    >
        <h2 class="text-14 font-medium">Suggested rule</h2>
        <p class="mt-2 text-13 text-ink-2">{{ rule.message }}</p>

        <p v-if="failure" class="mt-2 text-13 text-danger" role="alert" data-testid="suggested-rule-error">
            {{ failure }}
        </p>

        <div class="mt-4 flex flex-wrap items-center gap-3">
            <Button
                variant="accent"
                :disabled="working"
                data-testid="suggested-rule-require"
                @click="requireFullPayment"
            >
                Require full payment
            </Button>
            <Button variant="ghost" :disabled="working" data-testid="suggested-rule-dismiss" @click="dismiss">
                Dismiss
            </Button>
        </div>
    </section>
</template>
