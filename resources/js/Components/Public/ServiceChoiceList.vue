<script setup lang="ts">
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';

/**
 * The salon's price list, as choices rather than as a form control.
 *
 * The booking page reaches this from two places — the quiet "A different
 * service" line under the proposal, and the "Something else" block at the foot
 * of the day picker — because a customer changing their mind about the service
 * and a customer already browsing for a time are two different people arriving
 * at the same question. It is one component so those two cannot drift: it was
 * briefly two copies of the same list, and one of them already had a row the
 * other did not.
 *
 * Never a `<select>`. Nine services at nine prices and nine durations is nine
 * appointments, and a native select shows one of them at a time with the price
 * and the duration thrown away.
 */
type Service = {
    id: number;
    name: string;
    duration_minutes: number;
    price: { formatted: string };
};

defineProps<{
    services: Service[];
    /**
     * The service currently being proposed, marked in the list rather than
     * removed from it — a list of nine that silently omits the one you are
     * looking at is a list you cannot orient yourself in.
     */
    currentId?: number | null;
    /** Sentence case, on a hairline, like every other section caption here. */
    heading: string;
}>();

const emit = defineEmits<{ pick: [number] }>();
</script>

<template>
    <section>
        <h3 class="caption border-b border-b-rule pb-2">{{ heading }}</h3>
        <ul class="mt-2">
            <li v-for="service in services" :key="service.id">
                <ChoiceRow
                    :label="service.name"
                    :note="service.id === currentId ? 'The one on offer above' : undefined"
                    :meta="`${service.duration_minutes} min · ${service.price.formatted}`"
                    @pick="emit('pick', service.id)"
                />
            </li>
        </ul>
    </section>
</template>
