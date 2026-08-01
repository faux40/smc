<script setup lang="ts">
/**
 * The `${key}` vocabulary a card design is written in (custom-certs C4e) —
 * the list someone has open in one window while laying out a slide in
 * PowerPoint or Impress in the other.
 *
 * Two sources, one list: the built-in catalogue, identical for every org, and
 * a chosen training's own custom fields. Both are fetched, never restated
 * here — a key on this page that doesn't resolve at merge time would print as
 * literal text on a purchased card.
 */
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import CopyableKey from '@/components/CopyableKey.vue';
import { Label } from '@/components/ui/label';
import { useCardFieldsStore } from '@/stores/cardFields';
import { useCardMergeKeysStore } from '@/stores/cardMergeKeys';
import { useTrainingsStore } from '@/stores/trainings';

const keys = useCardMergeKeysStore();
const fields = useCardFieldsStore();
const trainings = useTrainingsStore();

const trainingId = ref('');
const loadingFields = ref(false);

const training = computed(
    () => trainings.library.find((t) => t.id === trainingId.value) ?? null,
);

const customFields = computed(() =>
    trainingId.value ? fields.forTraining(trainingId.value) : [],
);

/** The server's reason when it gave one, ours when it didn't. */
function reason(e: unknown, fallback: string): string {
    return (
        (e as { response?: { data?: { message?: string } } }).response?.data
            ?.message ?? fallback
    );
}

async function chooseTraining(id: string): Promise<void> {
    trainingId.value = id;

    if (id === '') {
        return;
    }

    loadingFields.value = true;

    try {
        await fields.load(id);
    } catch (e) {
        // finally-without-catch let this escape: the spinner cleared and
        // the training's fields simply never appeared, unexplained.
        toast.error(reason(e, "Could not load this training's card fields."));
    } finally {
        loadingFields.value = false;
    }
}

onMounted(async () => {
    // On this page an unexplained empty list is dangerous, not just untidy:
    // its own warning is that a mistyped key prints as literal text, and an
    // empty catalogue invites retyping keys from memory.
    try {
        await Promise.all([keys.load(), trainings.load()]);
    } catch (e) {
        toast.error(reason(e, 'Could not load the merge keys.'));
    }
});
</script>

<template>
    <div class="space-y-5">
        <p class="text-sm text-muted-foreground">
            Type these into the slide exactly as shown. Anything not on this
            list is printed as-is, so a typo in a key reaches the card.
        </p>

        <section
            v-for="group in keys.groups"
            :key="group.group"
            class="space-y-2"
        >
            <h3 class="text-sm font-semibold">{{ group.group }}</h3>
            <ul class="flex flex-wrap gap-3">
                <li v-for="k in group.keys" :key="k.key">
                    <CopyableKey :text="k.placeholder" />
                </li>
            </ul>
        </section>

        <section class="space-y-2 border-t border-border pt-4">
            <h3 class="text-sm font-semibold">A training's own fields</h3>
            <p class="text-sm text-muted-foreground">
                Custom fields are defined per training, and answered per class.
                Pick a training to see the keys its cards can use.
            </p>

            <div class="grid max-w-md gap-2">
                <Label for="keys_training">Training</Label>
                <select
                    id="keys_training"
                    data-testid="keys-training"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                    :value="trainingId"
                    @change="
                        chooseTraining(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">Pick a training…</option>
                    <option
                        v-for="t in trainings.library"
                        :key="t.id"
                        :value="t.id"
                    >
                        {{ t.name }}
                    </option>
                </select>
            </div>

            <p v-if="loadingFields" class="text-sm text-muted-foreground">
                Loading…
            </p>

            <template v-else-if="trainingId">
                <ul v-if="customFields.length" class="space-y-1">
                    <li
                        v-for="f in customFields"
                        :key="f.id"
                        class="flex flex-wrap items-baseline gap-2 text-sm"
                    >
                        <CopyableKey :text="f.placeholder" />
                        <span>{{ f.label }}</span>
                        <span
                            v-if="f.default_value"
                            class="text-xs text-muted-foreground"
                        >
                            default: {{ f.default_value }}
                        </span>
                    </li>
                </ul>
                <p v-else class="text-sm text-muted-foreground">
                    {{ training?.name ?? 'This training' }} defines no custom
                    fields. Add them on the training's page.
                </p>
            </template>
        </section>
    </div>
</template>
