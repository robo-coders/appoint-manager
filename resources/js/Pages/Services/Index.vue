<script setup lang="ts">
import AppLayout from '@/Layouts/AppLayout.vue';
import ConfirmDialog from '@/Components/ui/ConfirmDialog.vue';
import Money from '@/Components/ui/Money.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import Button from '@/Components/ui/Button.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import { penceToPoundsInput, poundsInputToPence } from '@/lib/money';
import type { ServiceRecord } from '@/types/models';
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive, ref } from 'vue';

const props = defineProps<{
    services: ServiceRecord[];
    staff: Array<{ id: number; name: string }>;
}>();

const sheetOpen = ref(false);
const editingId = ref<number | null>(null);
const deleteId = ref<number | null>(null);
const draggingId = ref<number | null>(null);

const form = useForm({
    name: '',
    description: '',
    duration_minutes: 60,
    buffer_minutes: 0,
    price: 0,
    deposit_amount: 0,
    is_active: true,
    staff_ids: [] as number[],
});

const money = reactive({
    price: '0.00',
    deposit: '0.00',
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.is_active = true;
    form.staff_ids = [];
    money.price = '0.00';
    money.deposit = '0.00';
    sheetOpen.value = true;
};

const openEdit = (service: ServiceRecord) => {
    editingId.value = service.id;
    form.name = service.name;
    form.description = service.description ?? '';
    form.duration_minutes = service.duration_minutes;
    form.buffer_minutes = service.buffer_minutes;
    form.is_active = service.is_active;
    form.staff_ids = [...service.staff_ids];
    money.price = penceToPoundsInput(service.price.amount);
    money.deposit = penceToPoundsInput(service.deposit_amount.amount);
    sheetOpen.value = true;
};

const submit = () => {
    form.price = poundsInputToPence(money.price);
    form.deposit_amount = poundsInputToPence(money.deposit);

    if (editingId.value) {
        form.patch(route('services.update', editingId.value), {
            onSuccess: () => (sheetOpen.value = false),
        });
    } else {
        form.post(route('services.store'), {
            onSuccess: () => (sheetOpen.value = false),
        });
    }
};

const toggleActive = (service: ServiceRecord) => {
    router.patch(route('services.update', service.id), {
        is_active: !service.is_active,
    });
};

const confirmDelete = () => {
    if (!deleteId.value) {
        return;
    }

    router.delete(route('services.destroy', deleteId.value), {
        onSuccess: () => (deleteId.value = null),
    });
};

const onDrop = (targetId: number) => {
    if (draggingId.value === null || draggingId.value === targetId) {
        return;
    }

    const ids = props.services.map((service) => service.id);
    const from = ids.indexOf(draggingId.value);
    const to = ids.indexOf(targetId);
    ids.splice(from, 1);
    ids.splice(to, 0, draggingId.value);
    draggingId.value = null;
    router.patch(route('services.reorder'), { ids });
};
</script>

<template>
    <AppLayout>
        <Head title="Services" />
        <PageHeader title="Services" description="What you offer, how long it takes, and what it costs.">
            <Button @click="openCreate">Add service</Button>
        </PageHeader>

        <div class="overflow-hidden rounded border border-rule bg-white">
            <table class="w-full text-left text-14">
                <thead class="border-b border-rule text-ink-2">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Duration</th>
                        <th class="px-4 py-3 font-medium">Price</th>
                        <th class="px-4 py-3 font-medium">Deposit</th>
                        <th class="px-4 py-3 font-medium">Active</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="service in services"
                        :key="service.id"
                        class="border-b border-rule last:border-0"
                        draggable="true"
                        @dragstart="draggingId = service.id"
                        @dragover.prevent
                        @drop="onDrop(service.id)"
                    >
                        <td class="px-4 py-3">{{ service.name }}</td>
                        <td class="px-4 py-3">{{ service.duration_minutes }} min</td>
                        <td class="px-4 py-3"><Money :value="service.price" /></td>
                        <td class="px-4 py-3"><Money :value="service.deposit_amount" /></td>
                        <td class="px-4 py-3">
                            <button type="button" class="text-accent" @click="toggleActive(service)">
                                {{ service.is_active ? 'On' : 'Off' }}
                            </button>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="mr-3 text-14" @click="openEdit(service)">Edit</button>
                            <button type="button" class="text-14 text-danger" @click="deleteId = service.id">
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <SlideOver :show="sheetOpen" :title="editingId ? 'Edit service' : 'Add service'" @close="sheetOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-14">Name</label>
                    <input v-model="form.name" class="w-full rounded border border-rule px-3 py-2 text-14" />
                    <p v-if="form.errors.name" class="mt-1 text-14 text-danger">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-14">Description</label>
                    <textarea v-model="form.description" class="w-full rounded border border-rule px-3 py-2 text-14" />
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-14">Duration (minutes)</label>
                        <input v-model.number="form.duration_minutes" type="number" min="5" step="5" class="w-full rounded border border-rule px-3 py-2 text-14" />
                        <p v-if="form.errors.duration_minutes" class="mt-1 text-14 text-danger">{{ form.errors.duration_minutes }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-14">Buffer (minutes)</label>
                        <input v-model.number="form.buffer_minutes" type="number" min="0" step="5" class="w-full rounded border border-rule px-3 py-2 text-14" />
                    </div>
                </div>
                <div class="grid grid-cols-2 gap-3">
                    <div>
                        <label class="mb-1 block text-14">Price</label>
                        <input v-model="money.price" class="w-full rounded border border-rule px-3 py-2 text-14" />
                        <p v-if="form.errors.price" class="mt-1 text-14 text-danger">{{ form.errors.price }}</p>
                    </div>
                    <div>
                        <label class="mb-1 block text-14">Deposit</label>
                        <input v-model="money.deposit" class="w-full rounded border border-rule px-3 py-2 text-14" />
                        <p v-if="form.errors.deposit_amount" class="mt-1 text-14 text-danger">{{ form.errors.deposit_amount }}</p>
                    </div>
                </div>
                <label class="flex items-center gap-2 text-14">
                    <input v-model="form.is_active" type="checkbox" />
                    Active
                </label>
                <fieldset class="space-y-2">
                    <legend class="text-14">Staff who can perform this</legend>
                    <label v-for="person in staff" :key="person.id" class="flex items-center gap-2 text-14">
                        <input v-model="form.staff_ids" type="checkbox" :value="person.id" />
                        {{ person.name }}
                    </label>
                </fieldset>
            </form>
            <template #footer>
                <Button :disabled="form.processing" @click="submit">Save changes</Button>
            </template>
        </SlideOver>

        <ConfirmDialog
            :show="deleteId !== null"
            title="Delete this service?"
            @close="deleteId = null"
            @confirm="confirmDelete"
        >
            This removes it from your list. Existing bookings stay as they are.
        </ConfirmDialog>
    </AppLayout>
</template>
