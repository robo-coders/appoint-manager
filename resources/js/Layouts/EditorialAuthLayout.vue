<script setup lang="ts">
/**
 * The shell both auth doors are drawn on.
 *
 * `/login` and `/admin/login` are the same page with different words in it:
 * masthead, a clay glow behind it, a two-column body with the display line
 * and a worked example on one side and the form on the other, a rule and a
 * footer. Both artboards are in `.design/mockups/login/`; both are on the
 * editorial token system, which is why the root element here carries
 * `.editorial-surface` — that class is what turns the tokens on, and turning
 * them on any higher would repaint the operator app behind it. The note at
 * the top of `resources/css/marketing-editorial-tokens.css` is the long
 * version.
 *
 * The artboards' second half — a specimen band captioned "ERROR STATES", the
 * four ways the page can fail drawn side by side — is not here and must not
 * come back. It is a page of design documentation, and rendered on the live
 * door it told every signed-out reader that four things had gone wrong. The
 * states it specified are built where they belong: the `.ed-alert` callouts
 * above the form and the `.ed-field-error` under each field, one at a time,
 * when one has actually happened.
 *
 * ── Why this is not `GuestLayout` ─────────────────────────────────────────
 *
 * `GuestLayout` is the right shape for the other six signed-out screens —
 * reset password, verify email, confirm password — which are errands with no
 * audience: one form, one sentence, no reason to introduce the product to
 * somebody who is already inside it. These two are doors, read once by
 * somebody deciding whether to trust what is behind them, and the artboards
 * answer that with type and a worked example. Nothing on this shell belongs
 * on a confirm-password screen, and vice versa.
 *
 * ── The three-area grid ───────────────────────────────────────────────────
 *
 * `lede`, `card` and `panel` are separate slots rather than one left column,
 * so that the narrow layout can put the form *between* the greeting and the
 * worked example. See the note beside `.ed-main` in the stylesheet.
 */
defineProps<{
    /** The console's "INTERNAL" chip. Absent on the operator door. */
    tag?: string;
    /** Where the wordmark goes. A real navigation — marketing is another host. */
    homeHref?: string;
}>();
</script>

<template>
    <div class="editorial-surface">
        <div class="ed-sheet">
            <div class="ed-glow" aria-hidden="true" />

            <header class="ed-nav">
                <component :is="homeHref ? 'a' : 'div'" :href="homeHref" class="ed-brand">
                    <!-- Drawn, not loaded: an <img> cannot read a custom
                         property, and the operator lockup is coloured in
                         tokens.css values that do not exist on this surface. -->
                    <span class="ed-mark" aria-hidden="true"><span /><span /></span>
                    <span class="ed-wordmark">{{ $page.props.appName }}</span>
                    <span v-if="tag" class="ed-tag">{{ tag }}</span>
                </component>

                <nav class="ed-navlinks">
                    <slot name="nav" />
                </nav>
            </header>

            <main class="ed-main">
                <div class="ed-lede">
                    <slot name="lede" />
                </div>

                <div class="ed-card-wrap">
                    <slot name="card" />
                </div>

                <div class="ed-panel-wrap">
                    <slot name="panel" />
                </div>
            </main>

            <footer class="ed-foot">
                <div class="ed-foot-rule" />
                <div class="ed-foot-inner">
                    <span><slot name="foot" /></span>
                    <nav class="ed-foot-nav">
                        <slot name="foot-nav" />
                    </nav>
                </div>
            </footer>
        </div>
    </div>
</template>

<style>
@import '../../css/auth-editorial.css';
</style>
