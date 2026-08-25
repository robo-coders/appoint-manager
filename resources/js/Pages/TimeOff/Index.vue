<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import { Head, router, useForm } from '@inertiajs/vue3';

const props = defineProps<{
    entries: Array<{
        id: number;
        user_name: string | null;
        starts_at_local: string | null;
        ends_at_local: string | null;
        reason: string | null;
        is_all_day: boolean;
    }>;
    staff: Array<{ id: number; name: string }>;
    timezone: string;
}>();

const form = useForm({
    user_id: props.staff[0]?.id ?? 0,
    starts_on: '',
    ends_on: '',
    start_time: '09:00',
    end_time: '17:00',
    is_all_day: false,
    reason: '',
});

const submit = () => {
    form.post(route('time-off.store'), {
        onSuccess: () => form.reset('reason', 'starts_on', 'ends_on'),
    });
};
</script>

<template>
    <AppLayout>
        <Head title="Time off" />
        <PageHeader title="Time off" :description="`Dates are stored in UTC and shown in ${timezone}.`" />

        <form class="mb-8 grid gap-3 rounded border border-rule bg-white p-6 md:grid-cols-2" @submit.prevent="submit">
            <div>
                <label class="mb-1 block text-14">Staff</label>
                <select v-model.number="form.user_id" class="w-full rounded border border-rule px-3 py-2 text-14">
                    <option :value="0" disabled>Select</option>
                    <option v-for="person in staff" :key="person.id" :value="person.id">
                        {{ person.name }}
                    </option>
                </select>
            </div>
            <div>
                <label class="mb-1 block text-14">Reason</label>
                <input v-model="form.reason" class="w-full rounded border border-rule px-3 py-2 text-14" />
            </div>
            <div>
                <label class="mb-1 block text-14">Starts on</label>
                <input v-model="form.starts_on" type="date" class="w-full rounded border border-rule px-3 py-2 text-14" />
            </div>
            <div>
                <label class="mb-1 block text-14">Ends on</label>
                <input v-model="form.ends_on" type="date" class="w-full rounded border border-rule px-3 py-2 text-14" />
                <p v-if="form.errors.ends_on" class="mt-1 text-14 text-danger">{{ form.errors.ends_on }}</p>
            </div>
            <label class="flex items-center gap-2 text-14 md:col-span-2">
                <input v-model="form.is_all_day" type="checkbox" />
                All day
            </label>
            <template v-if="!form.is_all_day">
                <div>
                    <label class="mb-1 block text-14">Start time</label>
                    <input v-model="form.start_time" type="time" class="w-full rounded border border-rule px-3 py-2 text-14" />
                </div>
                <div>
                    <label class="mb-1 block text-14">End time</label>
                    <input v-model="form.end_time" type="time" class="w-full rounded border border-rule px-3 py-2 text-14" />
                </div>
            </template>
            <div class="md:col-span-2">
                <Button type="submit" :disabled="form.processing">Add time off</Button>
            </div>
        </form>

        <div class="overflow-hidden rounded border border-rule bg-white">
            <table class="w-full text-left text-14">
                <thead class="border-b border-rule text-ink-2">
                    <tr>
                        <th class="px-4 py-3 font-medium">Staff</th>
                        <th class="px-4 py-3 font-medium">From</th>
                        <th class="px-4 py-3 font-medium">To</th>
                        <th class="px-4 py-3 font-medium">Reason</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="entry in entries" :key="entry.id" class="border-b border-rule last:border-0">
                        <td class="px-4 py-3">{{ entry.user_name }}</td>
                        <td class="px-4 py-3">{{ entry.starts_at_local }}</td>
                        <td class="px-4 py-3">{{ entry.ends_at_local }}</td>
                        <td class="px-4 py-3">{{ entry.reason }}</td>
                        <td class="px-4 py-3 text-right">
                            <button
                                type="button"
                                class="text-danger"
                                @click="router.delete(route('time-off.destroy', entry.id))"
                            >
                                Remove
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>
    </AppLayout>
</template>
