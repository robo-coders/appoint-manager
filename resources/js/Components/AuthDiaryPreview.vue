<script setup lang="ts">
import { computed } from 'vue';

/**
 * The diary, as an illustration, on the signed-out door.
 *
 * Three rows and a heading is the whole of it: enough to show a reclaimed slot
 * sitting between two ordinary appointments, which is the one thing this
 * product does that a paper diary cannot. From
 * `.design/mockups/login/operator-login.png`, where it is the only part of the
 * page that *shows* what the headline claims rather than asserting it.
 *
 * Nobody is signed in yet, so none of this is anybody's data. The three
 * appointments are fixed on purpose — an illustration that moved with a seed
 * would be a screenshot baseline that rots.
 *
 * ── The date is the exception, and it is today's ──────────────────────────
 *
 * The artboard prints "Thursday, 4 September", and it hardcoded both halves —
 * which is how it came to name a weekday that 4 September is not. A fixed date
 * on a door is also the one detail a salon owner reads as carelessness: the
 * page claims the diary kept working while she was out, over a card dated some
 * Thursday in a year that has gone.
 *
 * So the weekday and the date are computed and the appointments are not. The
 * browser's clock rather than a prop, because this is illustration rather than
 * data and a prop would put a fact about a page's decoration through the
 * controller; the e2e suite pins it with `page.clock.setFixedTime`, which is
 * what it already does for every other frame that moves.
 *
 * `en-GB` explicitly, not the visitor's locale. The rest of the card — 09:00,
 * 14:15, "60 min", the pound signs elsewhere on the page — is British, and a
 * date reading "September 4" over a 24-hour clock is neither one thing nor the
 * other. `formatToParts` rather than a format string, because en-GB's own
 * `long` format has no comma in it and the artboard's does.
 */
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
            <!--
                The count and the row it counts are the same fact, so the clay
                appears twice here and means once. DESIGN.md rations the accent
                by meaning, not by occurrence.
            -->
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
