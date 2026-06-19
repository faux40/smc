<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { trainingFormPayload, trainingToForm } from '@/lib/trainingForm';
import type {
    TrainingFormSource,
    TrainingFormState,
} from '@/lib/trainingForm';
import TrainingFields from '@/pages/trainings/Partials/TrainingFields.vue';
import { page as trainingsPage } from '@/routes/trainings';
import { useErrorStore } from '@/stores/errors';
import { useTrainingsStore } from '@/stores/trainings';

const FORM_CTX = 'form:training';

const props = defineProps<{ training: TrainingFormSource & { id: string } }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Trainings', href: trainingsPage() }],
    },
});

const store = useTrainingsStore();
const errorStore = useErrorStore();
const page = usePage();

const authUser = computed(
    () =>
        page.props.auth.user as {
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
        } | null,
);
const canManage = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

const form = ref<TrainingFormState>(trainingToForm(props.training));

// Dirty tracking against the last-saved baseline (compare serialized payloads
// so empty-string ⇄ null differences don't read as dirty).
const baseline = ref(JSON.stringify(trainingFormPayload(form.value)));
const isDirty = computed(
    () => JSON.stringify(trainingFormPayload(form.value)) !== baseline.value,
);

const saving = ref(false);

const save = async () => {
    saving.value = true;
    errorStore.clear(FORM_CTX);

    try {
        await store.update(props.training.id, trainingFormPayload(form.value));
        baseline.value = JSON.stringify(trainingFormPayload(form.value));
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save training',
        });
    } finally {
        saving.value = false;
    }
};

const deleteOpen = ref(false);
const deleting = ref(false);

const confirmDelete = async () => {
    deleting.value = true;
    errorStore.clear(FORM_CTX);

    try {
        await store.destroy(props.training.id);
        router.visit(trainingsPage().url);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to delete training',
        });
        deleting.value = false;
        deleteOpen.value = false;
    }
};
</script>

<template>
    <Head :title="training.name" />

    <div class="space-y-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading :title="training.name" />
            <Link
                :href="trainingsPage()"
                class="text-sm text-primary hover:underline"
            >
                ← Back to trainings
            </Link>
        </div>

        <ErrorBanner :context="FORM_CTX" />

        <form
            v-if="canManage"
            class="max-w-3xl space-y-4"
            @submit.prevent="save"
        >
            <TrainingFields v-model="form" :context="FORM_CTX" />

            <div class="flex items-center justify-between border-t border-border pt-4">
                <Button
                    type="button"
                    variant="destructive"
                    @click="deleteOpen = true"
                >
                    Delete training
                </Button>
                <Button type="submit" :disabled="!isDirty || saving">
                    {{ saving ? 'Saving…' : 'Save changes' }}
                </Button>
            </div>
        </form>

        <p v-else class="text-sm text-muted-foreground">
            You don't have permission to edit this training.
        </p>

        <Dialog v-model:open="deleteOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete this training?</DialogTitle>
                    <DialogDescription>
                        “{{ training.name }}” will be removed. Classes and
                        completions that already referenced it keep their
                        snapshot, but it can no longer be added to new classes
                        or requirements. This can’t be undone here.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="deleting"
                        @click="deleteOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="deleting"
                        @click="confirmDelete"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </div>
</template>
