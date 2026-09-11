<script setup lang="ts">
import AppLayout from "@/Layouts/AppLayout.vue";
import Banner from "@/Components/ui/Banner.vue";
import Button from "@/Components/ui/Button.vue";
import Callout from "@/Components/ui/Callout.vue";
import ConfirmDialog from "@/Components/ui/ConfirmDialog.vue";
import Field from "@/Components/ui/Field.vue";
import PageHeader from "@/Components/ui/PageHeader.vue";
import QuietAction from "@/Components/ui/QuietAction.vue";
import SaveState from "@/Components/ui/SaveState.vue";
import Select from "@/Components/ui/Select.vue";
import SettingsNav from "@/Components/Settings/SettingsNav.vue";
import Skeleton from "@/Components/ui/Skeleton.vue";
import TextInput from "@/Components/ui/TextInput.vue";
import Toggle from "@/Components/ui/Toggle.vue";
import { Head, Link, router, useForm, usePage } from "@inertiajs/vue3";
import { computed, onBeforeUnmount, onMounted, ref, useId } from "vue";

const props = defineProps<{
    loyalty: {
        enabled: boolean;
        name: string | null;
        sessions_required: number | null;
        reward: string | null;
        eligible_service_id: number | null;
        auto_stamp: boolean;
        auto_enrol: boolean;
        auto_apply_reward: boolean;
        show_visit_date: boolean;
        enrolled: number;
    };
    services: { value: number; label: string }[];
    limits: {
        min_visits: number;
        max_visits: number;
        max_reward_length: number;
        default_name: string;
        default_visits: number;
    };
    progress: Record<string, number>;
    preview: { visit_dates: string[] };
}>();

const page = usePage();

const sessionNoun = computed(
    () =>
        (page.props.vertical as { appointment_singular?: string })
            ?.appointment_singular ?? "appointment",
);

const form = useForm({
    enabled: props.loyalty.enabled,
    name: props.loyalty.name ?? props.limits.default_name,
    sessions_required:
        props.loyalty.sessions_required ?? props.limits.default_visits,
    reward: props.loyalty.reward ?? "",
    eligible_service_id: props.loyalty.eligible_service_id ?? "",
    auto_stamp: props.loyalty.auto_stamp,
    auto_enrol: props.loyalty.auto_enrol,
    auto_apply_reward: props.loyalty.auto_apply_reward,
    show_visit_date: props.loyalty.show_visit_date,
});

const savedAt = ref<number | null>(null);
const networkError = ref(false);
const reloading = ref(false);
const confirmingDisable = ref(false);
const leaveRequest = ref<{ href: string; method: string } | null>(null);
const visitsFieldId = useId();
const previewFull = ref(false);

const savedVisits = props.loyalty.sessions_required;

const visits = computed(() => {
    const value = Number(form.sessions_required);

    return Number.isFinite(value) ? value : props.limits.default_visits;
});

const atFloor = computed(() => visits.value <= props.limits.min_visits);
const atCeiling = computed(() => visits.value >= props.limits.max_visits);

const stepVisits = (by: number) => {
    const next = Math.min(
        props.limits.max_visits,
        Math.max(props.limits.min_visits, visits.value + by),
    );
    form.sessions_required = next;
};

const rewardUsed = computed(() => form.reward.length);
const rewardOver = computed(
    () => rewardUsed.value > props.limits.max_reward_length,
);

const serviceOptions = computed(() => [
    { value: "", label: "All services" },
    ...props.services.map((service) => ({
        value: String(service.value),
        label: service.label,
    })),
]);

const cardsAlreadyPast = computed(() => {
    if (savedVisits === null || visits.value >= savedVisits) {
        return 0;
    }

    return Object.entries(props.progress).reduce(
        (total, [stamps, cards]) =>
            Number(stamps) >= visits.value ? total + Number(cards) : total,
        0,
    );
});

const filled = computed(() =>
    previewFull.value
        ? visits.value
        : Math.max(1, Math.min(visits.value - 2, visits.value)),
);

const impressionDates = computed(() => {
    const real = props.preview.visit_dates
        .map((date) => new Date(`${date}T00:00:00`))
        .reverse();

    if (real.length >= filled.value) {
        return real.slice(real.length - filled.value);
    }

    const dates = [...real];
    let cursor = real.length > 0 ? new Date(real[0]) : new Date();

    while (dates.length < filled.value) {
        cursor = new Date(cursor);
        cursor.setDate(cursor.getDate() - 28);
        dates.unshift(new Date(cursor));
    }

    return dates.slice(dates.length - filled.value);
});

const impressions = computed(() =>
    Array.from({ length: visits.value }, (_, index) => {
        const isFilled = index < filled.value;
        const date = isFilled ? impressionDates.value[index] : null;

        return {
            key: index,
            filled: isFilled,
            closing: previewFull.value && index === visits.value - 1,
            label: date
                ? date.toLocaleDateString("en-GB", {
                      day: "numeric",
                      month: "short",
                  })
                : "",
            ordinal: String(index + 1).padStart(2, "0"),
        };
    }),
);

const submit = () => {
    networkError.value = false;

    form.patch(route("settings.loyalty.update"), {
        preserveScroll: true,
        onSuccess: () => {
            savedAt.value = Date.now();
            networkError.value = false;
        },
        onError: (errors) => {
            networkError.value = Object.keys(errors).length === 0;
        },
    });
};

const requestDisable = (next: boolean) => {
    if (!next && props.loyalty.enabled && props.loyalty.enrolled > 0) {
        confirmingDisable.value = true;

        return;
    }

    form.enabled = next;
};

const confirmDisable = () => {
    form.enabled = false;
    confirmingDisable.value = false;
};

const leaveAnyway = () => {
    const target = leaveRequest.value;

    leaveRequest.value = null;

    if (target === null) {
        return;
    }

    form.isDirty = false;
    router.visit(target.href, { method: target.method as "get" });
};

const warnBeforeUnload = (event: BeforeUnloadEvent) => {
    if (form.isDirty) {
        event.preventDefault();
    }
};

let stopBefore: (() => void) | undefined;
let stopStart: (() => void) | undefined;
let stopFinish: (() => void) | undefined;

onMounted(() => {
    stopBefore = router.on("before", (event) => {
        const visit = event.detail.visit;

        if (
            !form.isDirty ||
            visit.method !== "get" ||
            leaveRequest.value !== null
        ) {
            return;
        }

        leaveRequest.value = {
            href: visit.url.toString(),
            method: visit.method,
        };

        return false;
    });

    stopStart = router.on("start", (event) => {
        reloading.value = event.detail.visit.only.includes("loyalty");
    });

    stopFinish = router.on("finish", () => {
        reloading.value = false;
    });

    window.addEventListener("beforeunload", warnBeforeUnload);
});

onBeforeUnmount(() => {
    stopBefore?.();
    stopStart?.();
    stopFinish?.();
    window.removeEventListener("beforeunload", warnBeforeUnload);
});
</script>

<template>
    <AppLayout>
        <Head title="Loyalty" />

        <Banner
            v-if="networkError"
            tone="attention"
            message="That did not reach us — your changes are still here. Try Save again."
        />

        <PageHeader
            title="Settings"
            :description="`A stamp for every ${sessionNoun}, and one on the house when the card is full.`"
        />

        <SettingsNav current="loyalty" />

        <form class="mt-6 max-w-measure space-y-6" @submit.prevent="submit">
            <section class="rounded border border-rule bg-paper-sunk p-6">
                <div v-if="reloading" class="space-y-4">
                    <Skeleton shape="heading" />
                    <Skeleton shape="block" width="w-full" />
                    <Skeleton shape="text" :lines="2" />
                </div>

                <template v-else>
                    <div class="flex items-baseline justify-between gap-3">
                        <span class="text-13 text-ink">{{
                            form.name || limits.default_name
                        }}</span>
                        <QuietAction @click="previewFull = !previewFull">
                            {{
                                previewFull
                                    ? "Show a card part-way"
                                    : "Show a full card"
                            }}
                        </QuietAction>
                    </div>

                    <div
                        class="mt-6 grid max-w-booking grid-cols-5 gap-x-3 gap-y-4"
                    >
                        <span
                            v-for="impression in impressions"
                            :key="impression.key"
                            class="relative block aspect-square"
                            :class="
                                impression.closing
                                    ? 'text-accent'
                                    : impression.filled
                                      ? 'text-ink'
                                      : 'text-ink-4'
                            "
                        >
                            <svg
                                viewBox="0 0 40 40"
                                class="absolute inset-0 h-full w-full"
                                aria-hidden="true"
                            >
                                <circle
                                    cx="20"
                                    cy="20"
                                    r="19"
                                    fill="none"
                                    stroke="currentColor"
                                    :stroke-width="impression.filled ? 2 : 1"
                                    :stroke-dasharray="
                                        impression.filled ? undefined : '3 3'
                                    "
                                />
                                <circle
                                    v-if="impression.filled"
                                    cx="20"
                                    cy="20"
                                    r="15"
                                    fill="none"
                                    stroke="currentColor"
                                    stroke-width="1"
                                    opacity="0.5"
                                />
                                <line
                                    v-if="
                                        impression.filled &&
                                        form.show_visit_date
                                    "
                                    x1="12"
                                    y1="20"
                                    x2="28"
                                    y2="20"
                                    stroke="currentColor"
                                    stroke-width="1"
                                    opacity="0.45"
                                />
                            </svg>
                            <span
                                v-if="impression.filled"
                                class="absolute inset-0 flex flex-col items-center justify-center"
                                :class="form.show_visit_date ? 'gap-3' : ''"
                            >
                                <span
                                    v-if="form.show_visit_date"
                                    class="numeral whitespace-nowrap text-12 leading-none"
                                >
                                    {{ impression.label }}
                                </span>
                                <span
                                    class="numeral text-12 leading-none"
                                    :class="
                                        form.show_visit_date ? 'opacity-70' : ''
                                    "
                                >
                                    {{ impression.ordinal }}
                                </span>
                            </span>
                        </span>
                    </div>

                    <div
                        class="mt-6 flex items-baseline justify-between gap-3 border-t border-t-rule pt-4"
                    >
                        <span class="text-15 text-ink">
                            {{
                                previewFull
                                    ? "Free session ready"
                                    : `${filled} of ${visits} stamps`
                            }}
                        </span>
                        <span
                            v-if="previewFull"
                            class="numeral text-12 text-accent"
                            >card stamped out</span
                        >
                        <span v-else class="numeral text-12 text-ink-2"
                            >{{ visits - filled }} to go</span
                        >
                    </div>
                </template>
            </section>

            <Toggle
                :model-value="form.enabled"
                label="Run a loyalty card"
                hint="Off by default. While it is off, nothing about stamps appears to you or to your customers."
                @update:model-value="requestDisable"
            />

            <Callout
                v-if="!form.enabled && loyalty.enrolled > 0"
                title="This pauses cards that are part-way through"
            >
                <span class="numeral">{{ loyalty.enrolled }}</span>
                customer{{ loyalty.enrolled === 1 ? "" : "s" }} already
                {{ loyalty.enrolled === 1 ? "has" : "have" }} stamps. Nothing is
                deleted — they simply stop counting until this is switched back
                on.
            </Callout>

            <div v-if="reloading" class="space-y-4">
                <Skeleton shape="text" :lines="2" />
                <Skeleton shape="text" :lines="2" />
            </div>

            <template v-else>
                <div class="grid gap-4 md:grid-cols-2">
                    <TextInput
                        v-model="form.name"
                        label="Card name"
                        hint="For your list, not for customers. They see the count and the reward."
                        :error="form.errors.name"
                        :disabled="!form.enabled"
                        required
                    />
                    <Select
                        v-model="form.eligible_service_id"
                        label="Applies to"
                        :options="serviceOptions"
                        :error="form.errors.eligible_service_id"
                        :disabled="!form.enabled"
                        :hint="
                            services.length === 0
                                ? 'Add services to scope this to one.'
                                : 'Only this service earns a stamp.'
                        "
                    />
                </div>

                <p v-if="services.length === 0" class="text-12 text-ink-2">
                    <Link
                        :href="route('services.index')"
                        class="underline decoration-rule underline-offset-4"
                    >
                        Add services to scope this to one
                    </Link>
                </p>

                <div class="grid gap-4 md:grid-cols-2">
                    <Field
                        :input-id="visitsFieldId"
                        label="Visits needed"
                        :error="form.errors.sessions_required"
                        :hint="`Between ${limits.min_visits} and ${limits.max_visits}.`"
                        required
                    >
                        <div
                            :id="visitsFieldId"
                            class="flex h-control items-center gap-3"
                        >
                            <QuietAction
                                :aria-label="`One fewer ${sessionNoun}`"
                                :class="
                                    atFloor || !form.enabled
                                        ? 'pointer-events-none opacity-50'
                                        : ''
                                "
                                @click="stepVisits(-1)"
                            >
                                −
                            </QuietAction>
                            <span
                                data-testid="visits-count"
                                class="numeral min-w-8 text-center text-15 text-ink"
                                >{{ visits }}</span
                            >
                            <QuietAction
                                :aria-label="`One more ${sessionNoun}`"
                                :class="
                                    atCeiling || !form.enabled
                                        ? 'pointer-events-none opacity-50'
                                        : ''
                                "
                                @click="stepVisits(1)"
                            >
                                +
                            </QuietAction>
                        </div>
                    </Field>

                    <TextInput
                        v-model="form.reward"
                        label="Reward"
                        :hint="`${rewardUsed} of ${limits.max_reward_length} characters.`"
                        :error="form.errors.reward"
                        :disabled="!form.enabled"
                        required
                    />
                </div>

                <Callout
                    v-if="rewardOver"
                    tone="danger"
                    title="That reward is too long to save"
                >
                    Trim it to
                    <span class="numeral">{{ limits.max_reward_length }}</span>
                    characters. Nothing is cut off while you type.
                </Callout>

                <Callout
                    v-if="cardsAlreadyPast > 0"
                    tone="accent"
                    title="A shorter card completes people straight away"
                >
                    <span class="numeral">{{ cardsAlreadyPast }}</span>
                    customer{{ cardsAlreadyPast === 1 ? "" : "s" }} already
                    {{ cardsAlreadyPast === 1 ? "has" : "have" }}
                    <span class="numeral">{{ visits }}</span>
                    or more stamps. Saving marks
                    {{ cardsAlreadyPast === 1 ? "their card" : "their cards" }}
                    full and owes
                    {{ cardsAlreadyPast === 1 ? "them" : "them each" }} a free
                    session.
                </Callout>

                <Toggle
                    v-model="form.auto_stamp"
                    label="Stamp automatically"
                    :hint="`A stamp is added when you mark an ${sessionNoun} as done.`"
                    :disabled="!form.enabled"
                />
                <Toggle
                    v-model="form.auto_enrol"
                    label="Enrol every customer"
                    hint="Everybody joins on their next booking. Nobody has to be added by hand."
                    :disabled="!form.enabled"
                />
                <Toggle
                    v-model="form.auto_apply_reward"
                    label="Apply the reward automatically"
                    hint="A full card makes the next booking free, with no price and no deposit."
                    :disabled="!form.enabled"
                />
                <Toggle
                    v-model="form.show_visit_date"
                    label="Show the visit date on each stamp"
                    hint="The card doubles as a visit history."
                    :disabled="!form.enabled"
                />
            </template>

            <div class="flex items-center gap-4 border-t border-t-rule pt-4">
                <Button
                    type="submit"
                    :loading="form.processing"
                    :disabled="form.processing || rewardOver"
                    >Save</Button
                >
                <QuietAction
                    :class="
                        form.processing ? 'pointer-events-none opacity-50' : ''
                    "
                    @click="form.reset()"
                >
                    Cancel
                </QuietAction>
                <SaveState
                    :dirty="form.isDirty"
                    :processing="form.processing"
                    :saved-at="savedAt"
                />
            </div>
        </form>

        <ConfirmDialog
            :show="confirmingDisable"
            title="Switch the loyalty card off?"
            :body="`${loyalty.enrolled} customer${loyalty.enrolled === 1 ? '' : 's'} ${loyalty.enrolled === 1 ? 'is' : 'are'} part-way through a card. Their stamps are paused, not deleted, and they carry on from where they left off if you switch it back on.`"
            confirm-label="Switch it off"
            cancel-label="Leave it on"
            @close="confirmingDisable = false"
            @confirm="confirmDisable"
        />

        <ConfirmDialog
            :show="leaveRequest !== null"
            title="Leave without saving?"
            body="This tab has changes that have not been saved. Leaving now loses them."
            confirm-label="Leave"
            cancel-label="Stay here"
            @close="leaveRequest = null"
            @confirm="leaveAnyway"
        />
    </AppLayout>
</template>
