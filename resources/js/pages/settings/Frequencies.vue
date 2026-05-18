<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, reactive, ref } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useFieldErrors } from '@/composables/useFieldErrors';
import { edit } from '@/routes/frequencies';
import { useErrorStore } from '@/stores/errors';
import { useStdFrequenciesStore, type StdFrequencyRow } from '@/stores/stdFrequencies';

const PAGE_CTX = 'page:frequencies';
const FORM_CTX = 'form:frequency';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Standard frequencies', href: edit() }],
    },
});

const store = useStdFrequenciesStore();
const page = usePage();

const authUser = computed(
    () => page.props.auth.user as {
        org_id?: string;
        isOwner?: boolean;
        isSuperAdmin?: boolean;
        isAdmin?: boolean;
    } | null,
);
const canManage = computed(
    () => Boolean(authUser.value?.isOwner || authUser.value?.isSuperAdmin || authUser.value?.isAdmin),
);

interface FormState {
    name: string;
    repeat_days: number | '';
}

const form = reactive<FormState>({ name: '', repeat_days: '' });
const dialogOpen = ref(false);
const editingId = ref<string | null>(null);
const submitting = ref(false);

const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const title = computed(() => (editingId.value ? 'Edit frequency' : 'New frequency'));

onMounted(async () => {
    if (authUser.value?.org_id) store.subscribe(authUser.value.org_id);
    try {
        await store.load();
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, { fallback: 'Failed to load frequencies' });
    }
});

const openCreate = () => {
    editingId.value = null;
    form.name = '';
    form.repeat_days = '';
    errorStore.clear(FORM_CTX);
    dialogOpen.value = true;
};

const openEdit = (row: StdFrequencyRow) => {
    editingId.value = row.id;
    form.name = row.name;
    form.repeat_days = row.repeat_days;
    errorStore.clear(FORM_CTX);
    dialogOpen.value = true;
};

const submit = async () => {
    submitting.value = true;
    errorStore.clear(FORM_CTX);
    try {
        const days = typeof form.repeat_days === 'number' ? form.repeat_days : Number(form.repeat_days);
        if (editingId.value) {
            await store.update(editingId.value, form.name, days);
        } else {
            await store.create(form.name, days);
        }
        dialogOpen.value = false;
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, { fallback: 'Failed to save frequency' });
    } finally {
        submitting.value = false;
    }
};

const remove = async (row: StdFrequencyRow) => {
    if (!window.confirm(`Delete "${row.name}"?`)) return;
    try {
        await store.destroy(row.id);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, { fallback: 'Failed to delete frequency' });
    }
};
</script>

<template>
    <Head title="Standard frequencies" />

    <h1 class="sr-only">Standard frequencies</h1>

    <div class="flex flex-col space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Standard frequencies"
                description="Per-org timing presets used by Trainings, Requirements, and Assignments."
            />
            <Button v-if="canManage" @click="openCreate">+ Add frequency</Button>
        </div>

        <ErrorBanner :context="PAGE_CTX" />

        <div
            v-if="store.library.length === 0"
            class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No frequencies yet.
            <span v-if="canManage">Click "+ Add frequency" to create one.</span>
        </div>

        <table
            v-else
            class="min-w-full divide-y divide-border overflow-hidden rounded-md border border-border text-sm"
        >
            <thead class="bg-muted/40">
                <tr>
                    <th class="px-4 py-2 text-left font-medium">Name</th>
                    <th class="px-4 py-2 text-left font-medium">Repeat every</th>
                    <th class="px-4 py-2"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-border">
                <tr v-for="row in store.library" :key="row.id">
                    <td class="px-4 py-2">{{ row.name }}</td>
                    <td class="px-4 py-2">
                        {{ row.repeat_days }} day{{ row.repeat_days === 1 ? '' : 's' }}
                    </td>
                    <td class="space-x-3 px-4 py-2 text-right text-xs">
                        <button
                            v-if="row.can_edit"
                            type="button"
                            class="text-primary hover:underline"
                            @click="openEdit(row)"
                        >
                            Edit
                        </button>
                        <button
                            v-if="row.can_delete"
                            type="button"
                            class="text-destructive hover:underline"
                            @click="remove(row)"
                        >
                            Delete
                        </button>
                    </td>
                </tr>
            </tbody>
        </table>

        <Dialog v-model:open="dialogOpen">
            <DialogContent>
                <form @submit.prevent="submit" class="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{{ title }}</DialogTitle>
                        <DialogDescription>
                            A name + the number of days between recurrences.
                            Use whole days; RRULE-style scheduling is deferred
                            to a later phase.
                        </DialogDescription>
                    </DialogHeader>

                    <ErrorBanner :context="FORM_CTX" />

                    <div class="grid gap-2">
                        <Label for="freq_name">Name</Label>
                        <Input id="freq_name" v-model="form.name" required autofocus />
                        <InputError :message="fieldErrors.message('name')" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="freq_days">Repeat every (days)</Label>
                        <Input
                            id="freq_days"
                            v-model.number="form.repeat_days"
                            type="number"
                            min="1"
                            required
                        />
                        <InputError :message="fieldErrors.message('repeat_days')" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="submitting">
                            {{ submitting ? 'Saving…' : 'Save' }}
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </div>
</template>
