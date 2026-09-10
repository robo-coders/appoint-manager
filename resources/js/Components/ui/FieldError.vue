<script setup lang="ts">
/**
 * Inline, below the control, linked by aria-describedby. Never a toast alone.
 *
 * The default slot exists for the one message in the product that is not only
 * words: "an account with this email already exists" has a `Sign in instead`
 * link in it, because telling somebody they already have an account and then
 * making them find the door is the whole failure. `message` is still required
 * in that case — it is the plain-text version, and it is what the field's
 * `aria-describedby` points at — so a message can never exist as markup alone.
 */
defineProps<{ id?: string; message?: string }>();
</script>

<template>
    <p v-if="message" :id="id" class="text-12 text-danger" role="alert"><slot>{{ message }}</slot></p>
</template>
