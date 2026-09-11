<script setup lang="ts">
defineProps<{
    tag?: string;
    homeHref?: string;
}>();
</script>

<template>
    <div class="editorial-surface">
        <div class="ed-sheet">
            <div class="ed-glow" aria-hidden="true" />

            <header class="ed-nav">
                <component :is="homeHref ? 'a' : 'div'" :href="homeHref" class="ed-brand">
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
