<script setup lang="ts">
import Button from '@/Components/ui/Button.vue';
import Card from '@/Components/ui/Card.vue';
import { toast } from '@/lib/toast';

defineProps<{
    url: string;
    live: boolean;
    qrUrl: string | null;
    qrDownloadUrl: string | null;
}>();

const copy = async (url: string) => {
    try {
        await navigator.clipboard.writeText(url);
        toast('Copied.');
    } catch {
        toast('Could not copy.', { tone: 'danger' });
    }
};
</script>

<template>
    <Card title="Booking link">
        <p class="font-mono text-14 break-all text-ink">{{ url }}</p>
        <p v-if="!live" class="mt-2 text-13">
            The booking page is not live yet, so this link will not open.
        </p>
        <div v-if="live && qrUrl" class="mt-4 space-y-3">
            <img :src="qrUrl" alt="QR code for the booking page" width="160" height="160" class="block" />
            <div class="flex flex-wrap gap-2">
                <Button variant="secondary" @click="copy(url)">Copy</Button>
                <Button v-if="qrDownloadUrl" variant="secondary" :href="qrDownloadUrl">Download</Button>
            </div>
        </div>
    </Card>
</template>
