<script setup lang="ts">
import ChoiceRow from '@/Components/ui/ChoiceRow.vue';

type Service = {
    id: number;
    name: string;
    duration_minutes: number;
    price: { formatted: string };
};

defineProps<{
    services: Service[];
    currentId?: number | null;
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
