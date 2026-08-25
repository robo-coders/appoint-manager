<script setup lang="ts">
import { DEFAULT_STAFF_COLOUR } from '@/lib/staffColour';
import AppLayout from '@/Layouts/AppLayout.vue';
import Button from '@/Components/ui/Button.vue';
import PageHeader from '@/Components/ui/PageHeader.vue';
import SlideOver from '@/Components/ui/SlideOver.vue';
import type { StaffRecord } from '@/types/models';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import { ref } from 'vue';

const props = defineProps<{
    staff: StaffRecord[];
}>();

const page = usePage();
const sheetOpen = ref(false);
const editingId = ref<number | null>(null);

const form = useForm({
    name: '',
    email: '',
    colour: DEFAULT_STAFF_COLOUR,
    is_bookable: true,
    is_active: true,
});

const openCreate = () => {
    editingId.value = null;
    form.reset();
    form.colour = DEFAULT_STAFF_COLOUR;
    form.is_bookable = true;
    form.is_active = true;
    sheetOpen.value = true;
};

const openEdit = (person: StaffRecord) => {
    editingId.value = person.id;
    form.name = person.name;
    form.email = person.email;
    form.colour = person.colour ?? DEFAULT_STAFF_COLOUR;
    form.is_bookable = person.is_bookable;
    form.is_active = person.is_active;
    sheetOpen.value = true;
};

const submit = () => {
    if (editingId.value) {
        form.patch(route('staff.update', editingId.value), {
            onSuccess: () => (sheetOpen.value = false),
        });
    } else {
        form.post(route('staff.store'), {
            onSuccess: () => (sheetOpen.value = false),
        });
    }
};

const deactivate = (person: StaffRecord) => {
    router.patch(route('staff.update', person.id), { is_active: false });
};
</script>

<template>
    <AppLayout>
        <Head title="Staff" />
        <PageHeader title="Staff" description="People who work in the business.">
            <Button @click="openCreate">Add staff</Button>
        </PageHeader>

        <div class="overflow-hidden rounded border border-rule bg-white">
            <table class="w-full text-left text-14">
                <thead class="border-b border-rule text-ink-2">
                    <tr>
                        <th class="px-4 py-3 font-medium">Name</th>
                        <th class="px-4 py-3 font-medium">Email</th>
                        <th class="px-4 py-3 font-medium">Bookable</th>
                        <th class="px-4 py-3 font-medium">Status</th>
                        <th class="px-4 py-3 font-medium"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr v-for="person in staff" :key="person.id" class="border-b border-rule last:border-0">
                        <td class="px-4 py-3">
                            <span
                                class="mr-2 inline-block h-2 w-2 rounded"
                                :style="{ backgroundColor: person.colour ?? DEFAULT_STAFF_COLOUR }"
                            />
                            {{ person.name }}
                        </td>
                        <td class="px-4 py-3">{{ person.email }}</td>
                        <td class="px-4 py-3">{{ person.is_bookable ? 'Yes' : 'No' }}</td>
                        <td class="px-4 py-3">{{ person.is_active ? 'Active' : 'Inactive' }}</td>
                        <td class="px-4 py-3 text-right">
                            <button type="button" class="mr-3" @click="openEdit(person)">Edit</button>
                            <button
                                v-if="person.is_active && person.id !== page.props.auth.user?.id"
                                type="button"
                                class="text-danger"
                                @click="deactivate(person)"
                            >
                                Deactivate
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <SlideOver :show="sheetOpen" :title="editingId ? 'Edit staff' : 'Add staff'" @close="sheetOpen = false">
            <form class="space-y-4" @submit.prevent="submit">
                <div>
                    <label class="mb-1 block text-14">Name</label>
                    <input v-model="form.name" class="w-full rounded border border-rule px-3 py-2 text-14" />
                    <p v-if="form.errors.name" class="mt-1 text-14 text-danger">{{ form.errors.name }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-14">Email</label>
                    <input v-model="form.email" type="email" class="w-full rounded border border-rule px-3 py-2 text-14" />
                    <p v-if="form.errors.email" class="mt-1 text-14 text-danger">{{ form.errors.email }}</p>
                </div>
                <div>
                    <label class="mb-1 block text-14">Colour</label>
                    <input v-model="form.colour" type="color" class="h-10 w-16 rounded border border-rule" />
                </div>
                <label class="flex items-center gap-2 text-14">
                    <input v-model="form.is_bookable" type="checkbox" />
                    Bookable
                </label>
                <label v-if="editingId" class="flex items-center gap-2 text-14">
                    <input v-model="form.is_active" type="checkbox" />
                    Active
                </label>
            </form>
            <template #footer>
                <Button :disabled="form.processing" @click="submit">Save</Button>
            </template>
        </SlideOver>
    </AppLayout>
</template>
