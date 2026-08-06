<script setup lang="ts">
import { Head, Link, router, usePage } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import AttachmentsList from '@/components/AttachmentsList.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import TagsField from '@/components/TagsField.vue';
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
import type { TrainingFormSource, TrainingFormState } from '@/lib/trainingForm';
import CardFieldsEditor from '@/pages/trainings/Partials/CardFieldsEditor.vue';
import TrainingFields from '@/pages/trainings/Partials/TrainingFields.vue';
import { page as trainingsPage } from '@/routes/trainings';
import { useErrorStore } from '@/stores/errors';
import { useTrainingsStore } from '@/stores/trainings';

const FORM_CTX = 'form:training';

const props = withDefaults(
    defineProps<{
        training: TrainingFormSource & { id: string };
        tagIds?: string[];
    }>(),
    { tagIds: () => [] },
);

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
            class="max-w-5xl space-y-4"
            @submit.prevent="save"
        >
            <TrainingFields
                v-model="form"
                :context="FORM_CTX"
                :self-id="training.id"
            />

            <div
                class="flex items-center justify-between border-t border-border pt-4"
            >
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

        <!--
            Supporting material: the deck, handouts, checklists, test forms.
            Listed for everyone — an instructor needs the handouts — but only
            managers may add or retitle them, matching who owns the training
            library itself. The server enforces the same rule.
        -->
        <div class="max-w-5xl space-y-2">
            <h2 class="text-sm font-semibold">Files</h2>
            <AttachmentsList
                morphable-type="App\Models\Training"
                :morphable-id="training.id"
                :can-upload="canManage"
            />
        </div>

        <!--
            Not gated on canManage: tags are descriptive, not access-control,
            and TagsController lets any org member attach one. Only the library
            (creating/renaming tags) is admin-only, which is what the flag says.
            Classes inherit these tags when this training is added as a topic.
        -->
        <div class="max-w-5xl space-y-2">
            <h2 class="text-sm font-semibold">Tags</h2>
            <TagsField
                morphable-type="App\Models\Training"
                :morphable-id="training.id"
                :initial-tag-ids="props.tagIds"
                :can-manage-library="canManage"
            />
        </div>

        <!--
            Custom card fields: a set of definitions, saved on its own. Kept
            out of the form above because membership and order are properties
            of the set, and it PUTs rather than PATCHes. Sits after the
            permission notice so the form's v-if/v-else stay adjacent.
        -->
        <CardFieldsEditor v-if="canManage" :training-id="training.id" />

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
