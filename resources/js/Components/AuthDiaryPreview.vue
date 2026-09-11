<script setup lang="ts">
import { computed } from 'vue';

const today = computed(() => {
    const parts = new Intl.DateTimeFormat('en-GB', {
        weekday: 'long',
        day: 'numeric',
        month: 'long',
    }).formatToParts(new Date());

    const of = (type: Intl.DateTimeFormatPartTypes) => parts.find((p) => p.type === type)?.value ?? '';

    return `${of('weekday')}, ${of('day')} ${of('month')}`;
});
</script>

<template>
    <div class="ed-panel">
        <div class="ed-panel-head">
            <span class="ed-panel-title">{{ today }}</span>
            <span class="ed-panel-note">2 slots reclaimed today</span>
        </div>

        <div class="ed-row">
            <span class="ed-time">09:00</span>
            <span class="ed-slot">Amy Fraser <span class="ed-quiet">· 60 min</span></span>
        </div>

        <div class="ed-row">
            <span class="ed-time">12:00</span>
            <span class="ed-slot ed-slot--reclaimed">12:00 · reclaimed</span>
        </div>

        <div class="ed-row">
            <span class="ed-time">14:15</span>
            <span class="ed-slot">Marie Okafor <span class="ed-quiet">· deposit paid</span></span>
        </div>
    </div>
</template>
