<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import QuietAction from '@/Components/ui/QuietAction.vue';
import TextInput from '@/Components/ui/TextInput.vue';
import { toast } from '@/lib/toast';
import { computed, nextTick, ref } from 'vue';

const props = defineProps<{
    label: string;
    sublabel: string;
    url: string;
    lastPulledAt: string | null;
    subscriberName: string;
    onCopy?: (url: string) => void;
    onRegenerate: () => Promise<void>;
}>();

const field = ref<InstanceType<typeof TextInput> | null>(null);
const banner = ref<HTMLElement | null>(null);

const confirming = ref(false);
const pending = ref(false);

const pulled = computed(() =>
    props.lastPulledAt === null ? 'Not yet synced' : `Last pulled ${props.lastPulledAt}`,
);

const settled = () => {
    toast.success('Link copied');
    props.onCopy?.(props.url);
};

const writeViaClipboard = async (value: string): Promise<boolean> => {
    if (typeof navigator.clipboard?.writeText !== 'function') return false;

    try {
        await navigator.clipboard.writeText(value);

        return true;
    } catch {
        return false;
    }
};

const writeViaSelection = (value: string): boolean => {
    try {
        const scratch = document.createElement('textarea');
        scratch.value = value;
        scratch.setAttribute('readonly', 'readonly');
        scratch.style.position = 'fixed';
        scratch.style.top = '0';
        scratch.style.opacity = '0';
        document.body.appendChild(scratch);
        scratch.select();
        const written = document.execCommand('copy');
        document.body.removeChild(scratch);

        return written;
    } catch {
        return false;
    }
};

const copy = async () => {
    if ((await writeViaClipboard(props.url)) || writeViaSelection(props.url)) {
        settled();

        return;
    }

    field.value?.select();
    toast.error('Copy failed — text is selected, press ⌘C / Ctrl+C.');
};

const ask = async () => {
    if (pending.value) return;

    confirming.value = true;
    await nextTick();
    banner.value?.focus();
};

const dismiss = () => {
    confirming.value = false;
};

const confirm = async () => {
    if (pending.value) return;

    confirming.value = false;
    pending.value = true;

    try {
        await props.onRegenerate();
        toast.success('Link regenerated — the old link stopped working');
    } catch {
        toast.error(
            'That did not go through, so the current link is still the live one. Nobody’s calendar has changed.',
            { actionLabel: 'Try again', onAction: ask },
        );
    } finally {
        pending.value = false;
    }
};
</script>

<template>
    <div class="border-b border-b-rule py-4" data-testid="calendar-feed-row">
        <div class="mb-3 flex flex-wrap items-baseline gap-3">
            <span class="text-14 font-medium">{{ label }}</span>
            <span class="text-13 text-ink-2">{{ sublabel }}</span>
            <span class="flex-1" />
            <span class="font-mono text-12 text-ink-2">{{ pulled }}</span>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <div class="min-w-col-when flex-1">
                <TextInput
                    ref="field"
                    :label="`Calendar feed address for ${label}`"
                    label-hidden
                    :model-value="url"
                    readonly
                    mono
                    truncate
                    data-testid="calendar-feed-url"
                />
            </div>
            <Button variant="secondary" data-testid="calendar-feed-copy" @click="copy">Copy</Button>
            <QuietAction :disabled="pending" data-testid="calendar-feed-regenerate" @click="ask">
                Regenerate link
            </QuietAction>
        </div>

        <div
            v-if="confirming"
            ref="banner"
            tabindex="-1"
            role="group"
            :aria-label="`Regenerate the calendar link for ${label}`"
            class="appear mt-3 flex flex-wrap items-center gap-4 rounded border border-accent-rule px-3 py-3"
            data-testid="calendar-feed-confirm"
            @keydown.esc="dismiss"
        >
            <p class="min-w-col-when flex-1 text-13">
                Regenerating breaks the subscription on every device already using this link.
                {{ subscriberName }} will need to remove the old calendar and subscribe again.
            </p>
            <div class="flex items-center gap-3">
                <Button
                    variant="accent-solid"
                    :loading="pending"
                    data-testid="calendar-feed-confirm-regenerate"
                    @click="confirm"
                >
                    Regenerate anyway
                </Button>
                <QuietAction @click="dismiss">Keep current link</QuietAction>
            </div>
        </div>
    </div>
</template>
