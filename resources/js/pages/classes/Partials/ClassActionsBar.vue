<script setup lang="ts">
/*
 * The compliance "act on the selected people" bar: create a new class from the
 * selection (presetting the relevant trainings) or — when the selection is for
 * a single training — add them to an existing scheduled class. Shared by the
 * training / requirement / not-required detail screens so the enroll-then-go
 * behaviour lives in one place.
 *
 * Creating a class navigates to it (you finish scheduling there). Adding to an
 * existing class stays put + toasts, so you can keep working through the list.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { router } from '@inertiajs/vue3';
import { toast } from 'vue-sonner';
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

// Emitted after a successful add-to-existing so the page can clear its selection.
const emit = defineEmits<{ (e: 'done'): void }>();

const classes = useClassesStore();
const createOpen = ref(false);
const addOpen = ref(false);
const count = computed(() => props.selectedUserIds.length);

// #7 — know up front whether any scheduled class includes this training, so the
// "add to existing" button can disable + explain rather than open to an empty
// modal. null = not checked yet (single-training screens only).
const hasEligibleClass = ref<boolean | null>(null);
async function checkEligibility(): Promise<void> {
    if (!props.addTrainingId) {
        return;
    }
    try {
        const list = await classes.fetchForTraining(props.addTrainingId);
        hasEligibleClass.value = list.length > 0;
    } catch {
        hasEligibleClass.value = null; // unknown → don't block the user
    }
}
onMounted(checkEligibility);
watch(() => props.addTrainingId, checkEligibility);

const addDisabled = computed(
    () => count.value === 0 || hasEligibleClass.value === false,
);
const addHint = computed(() =>
    hasEligibleClass.value === false
        ? 'No scheduled class includes this training yet'
        : undefined,
);

async function onClassSaved(detail: { id: string }): Promise<void> {
    if (props.selectedUserIds.length > 0) {
        await classes.bulkEnroll(detail.id, {
            enroll: props.selectedUserIds,
            unenroll: [],
        });
    }
    router.visit(showPage(detail.id));
}

function onAdded(): void {
    const n = props.selectedUserIds.length;
    toast.success(`Added ${n} ${n === 1 ? 'person' : 'people'} to the class.`);
    emit('done');
    // A newly added class may now have eligibility implications elsewhere; the
    // count is unaffected, so just stay on the list.
}
</script>

<template>
    <div class="flex items-center gap-3">
        <Button
            v-if="addTrainingId"
            type="button"
            variant="outline"
            :disabled="addDisabled"
            :title="addHint"
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
