<script setup lang="ts">
/*
 * The compliance "act on the selected people" bar: create a new class from the
 * selection (presetting the relevant trainings) or — when the selection is for
 * a single training — add them to an existing scheduled class. Shared by the
 * training / requirement / not-required detail screens so the enroll-then-go
 * behaviour lives in one place.
 */
import { computed, ref } from 'vue';
import { router } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import AddToClassModal from '@/pages/classes/Partials/AddToClassModal.vue';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import { showPage } from '@/routes/classes';
import { useClassesStore } from '@/stores/classes';

const props = defineProps<{
    selectedUserIds: string[];
    // Trainings to preset on a newly created class (one for a single-training
    // screen, the distinct selected trainings for a requirement).
    createTrainingIds: string[];
    presetName?: string;
    // When set, the selection is for one training → offer "add to existing".
    addTrainingId?: string;
    addTrainingName?: string;
}>();

const classes = useClassesStore();
const createOpen = ref(false);
const addOpen = ref(false);
const count = computed(() => props.selectedUserIds.length);

async function onClassSaved(detail: { id: string }): Promise<void> {
    if (props.selectedUserIds.length > 0) {
        await classes.bulkEnroll(detail.id, {
            enroll: props.selectedUserIds,
            unenroll: [],
        });
    }
    router.visit(showPage(detail.id));
}

function onAdded(classId: string): void {
    router.visit(showPage(classId));
}
</script>

<template>
    <div class="flex items-center gap-3">
        <Button
            v-if="addTrainingId"
            type="button"
            variant="outline"
            :disabled="count === 0"
            data-testid="add-to-class"
            @click="addOpen = true"
        >
            Add to existing class ({{ count }})
        </Button>
        <Button
            type="button"
            :disabled="count === 0 || createTrainingIds.length === 0"
            data-testid="assemble-class"
            @click="createOpen = true"
        >
            Create class with selected ({{ count }})
        </Button>

        <ClassFormModal
            v-model:open="createOpen"
            :preset-training-ids="createTrainingIds"
            :preset-name="presetName"
            @saved="onClassSaved"
        />
        <AddToClassModal
            v-if="addTrainingId"
            v-model:open="addOpen"
            :training-id="addTrainingId"
            :training-name="addTrainingName ?? ''"
            :user-ids="selectedUserIds"
            @added="onAdded"
        />
    </div>
</template>
