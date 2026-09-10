<script setup lang="ts">
import CalendarFeedRow from '@/Components/CalendarFeedRow.vue';
import SettingsNav from '@/Components/Settings/SettingsNav.vue';
import RadioGroup from '@/Components/ui/RadioGroup.vue';
import AppLayout from '@/Layouts/AppLayout.vue';
import { toast } from '@/lib/toast';
import { Head, router, usePage } from '@inertiajs/vue3';
import CalendarDays from 'lucide-vue-next/dist/esm/icons/calendar-days';
import Download from 'lucide-vue-next/dist/esm/icons/download';
import Smartphone from 'lucide-vue-next/dist/esm/icons/smartphone';
import { computed, ref, watch } from 'vue';

type Feed = {
    staffId: number;
    staffName: string;
    role: string;
    url: string;
    lastPulledAt: string | null;
    isOwn: boolean;
};

const props = defineProps<{
    feeds: Feed[];
    salonFeed: { url: string; lastPulledAt: string | null };
    contentMode: 'full' | 'busy_only';
    verticalNoun: string;
    sampleService: string;
    firstSyncHours: number;
}>();

const page = usePage();
const appName = computed(() => String(page.props.appName ?? 'the app'));

const rowCount = computed(() => props.feeds.length + 1);
const salonLabel = computed(() => `Whole ${props.verticalNoun}`);

const firstName = (name: string) => name.trim().split(/\s+/)[0] ?? name;

const regenerate = (scope: 'staff' | 'salon', staffId: number | null) =>
    new Promise<void>((resolve, reject) => {
        router.post(
            route('settings.calendar-sync.regenerate'),
            { scope, staff_id: staffId },
            {
                preserveScroll: true,
                only: ['feeds', 'salonFeed'],
                onSuccess: () => resolve(),
                onError: () => reject(new Error('regenerate failed')),
                onCancel: () => reject(new Error('regenerate cancelled')),
            },
        );
    });

const mode = ref<'full' | 'busy_only'>(props.contentMode);

watch(
    () => props.contentMode,
    (confirmed) => (mode.value = confirmed),
);

const modeError = ref(false);

watch(mode, (chosen) => {
    if (chosen === props.contentMode) return;

    modeError.value = false;

    router.post(
        route('settings.calendar-sync.content-mode'),
        { mode: chosen },
        {
            preserveScroll: true,
            onError: () => {
                mode.value = props.contentMode;
                modeError.value = true;
            },
            onSuccess: () => toast('Saved.'),
        },
    );
});

const contentOptions = computed(() => [
    {
        value: 'full',
        label: 'Include customer names and service details',
        hint: `Events read "Claire Donnelly — ${props.sampleService}" so staff can see the day at a glance outside ${appName.value}.`,
        sample: `09:30 Claire Donnelly — ${props.sampleService}`,
    },
    {
        value: 'busy_only',
        label: 'Show only "Busy" blocks',
        hint: 'Times are blocked out with no personal data. Safest if the feed sits on a shared or personal device.',
        sample: '09:30 Busy',
    },
]);

const guides = computed(() => [
    {
        name: 'Google Calendar',
        icon: CalendarDays,
        steps: [
            'Open Google Calendar on a computer — the phone app cannot add feeds.',
            'In the left column, click the plus beside Other calendars.',
            'Choose From URL and paste the link above.',
            `Click Add calendar. First sync takes up to ${props.firstSyncHours} hours; after that Google refreshes on its own schedule.`,
        ],
    },
    {
        name: 'Apple Calendar (iPhone)',
        icon: Smartphone,
        steps: [
            'Settings, then Calendar, then Accounts.',
            'Add Account, then Other, then Add Subscribed Calendar.',
            'Paste the link into Server and tap Next, then Save.',
            'Set Refresh to Every 15 minutes for the quickest updates.',
        ],
    },
    {
        name: 'Anything else (.ics)',
        icon: Download,
        steps: [
            'Any app that accepts a webcal or https iCal subscription will work.',
            'Paste the link as a subscription, not an import — imports do not update.',
            'Outlook: Add calendar, then Subscribe from web.',
        ],
    },
]);

const openGuide = ref<string | null>('Google Calendar');

const onGuideToggle = (name: string, event: Event) => {
    const element = event.target as HTMLDetailsElement;

    if (element.open) {
        openGuide.value = name;

        return;
    }

    if (openGuide.value === name) openGuide.value = null;
};
</script>

<template>
    <AppLayout>
        <Head title="Calendar sync" />

        <div class="mb-6">
            <p class="eyebrow">Screen 08 · settings</p>
            <h1 class="display-light mt-2 text-34">Calendar sync</h1>
        </div>

        <SettingsNav current="calendar" />

        <div class="mt-8 max-w-record">
            <p class="max-w-measure text-14 text-ink-2">
                Subscribe to a read-only iCal feed and your {{ appName }} appointments appear in Google
                Calendar or Apple Calendar, refreshing on their own. Editing an event there does not change
                the booking.
            </p>

            <section class="mt-8">
                <div class="flex items-baseline justify-between gap-4 border-b border-b-rule pb-2">
                    <p class="eyebrow">Feeds by staff member</p>
                    <p class="font-mono text-12 text-ink-2">{{ rowCount }} feeds · read-only</p>
                </div>

                <CalendarFeedRow
                    v-for="feed in feeds"
                    :key="feed.staffId"
                    :label="feed.staffName"
                    :sublabel="feed.role"
                    :url="feed.url"
                    :last-pulled-at="feed.lastPulledAt"
                    :subscriber-name="feed.isOwn ? 'You' : firstName(feed.staffName)"
                    :on-regenerate="() => regenerate('staff', feed.staffId)"
                />

                <CalendarFeedRow
                    :label="salonLabel"
                    sublabel="All staff combined"
                    :url="salonFeed.url"
                    :last-pulled-at="salonFeed.lastPulledAt"
                    subscriber-name="Anyone subscribed"
                    :on-regenerate="() => regenerate('salon', null)"
                />

                <p v-if="feeds.length === 0" class="mt-3 text-13 text-ink-2">
                    Nobody on the staff screen is taking bookings yet, so the combined feed above is the only
                    one there is to hand out.
                </p>
            </section>

            <section class="mt-8">
                <p class="eyebrow border-b border-b-rule pb-2">What the feed contains</p>

                <div class="pt-4">
                    <RadioGroup
                        v-model="mode"
                        legend="What the feed contains"
                        legend-hidden
                        :options="contentOptions"
                        :error="
                            modeError
                                ? 'That did not save, so the feeds are still showing what they were. Try again.'
                                : undefined
                        "
                    />

                    <p class="mt-4 max-w-measure text-13 text-ink-2">
                        Applies to every staff feed. Anyone holding a link can read whatever this setting
                        exposes, so keep it to Busy if links are shared with family calendars.
                    </p>
                </div>
            </section>

            <section class="mt-8">
                <p class="eyebrow border-b border-b-rule pb-2">How to add this</p>

                <details
                    v-for="guide in guides"
                    :key="guide.name"
                    class="border-b border-b-rule"
                    :open="openGuide === guide.name"
                    @toggle="onGuideToggle(guide.name, $event)"
                >
                    <summary
                        class="flex min-h-tap cursor-pointer list-none items-center justify-between gap-4 py-3 [&::-webkit-details-marker]:hidden"
                    >
                        <span class="flex items-center gap-3">
                            <component :is="guide.icon" :size="17" class="text-ink-2" aria-hidden="true" />
                            <span class="text-13 font-medium">{{ guide.name }}</span>
                        </span>
                        <span class="font-mono text-14 text-ink-2" aria-hidden="true">
                            {{ openGuide === guide.name ? '–' : '+' }}
                        </span>
                    </summary>

                    <ol class="pb-4">
                        <li v-for="(step, index) in guide.steps" :key="step" class="flex gap-3 py-1">
                            <span class="w-4 shrink-0 font-mono text-12 text-ink-3">{{ index + 1 }}.</span>
                            <span class="text-13 text-ink-2">{{ step }}</span>
                        </li>
                    </ol>
                </details>
            </section>
        </div>
    </AppLayout>
</template>
