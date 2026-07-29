<script setup lang="ts">
/*
 * Card-template library: the uploaded PPTX/ODP card designs (one card per
 * template — slide 1 the front, an optional slide 2 the back). Managers see
 * the list because they print from it; Admins upload, replace, rename and
 * delete their org's, while system templates are read-only here.
 *
 * Everything shown about a template was read from the file at upload, so the
 * list is a description of the design rather than of what someone typed.
 */
import { computed, reactive, ref } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import InputError from '@/components/InputError.vue';
import { Badge } from '@/components/ui/badge';
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
import { fromPoints } from '@/lib/cardGeometry';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import type { CardTemplateRow } from '@/stores/cardTemplates';
import { useErrorStore } from '@/stores/errors';

const FORM_CTX = 'form:card-template';

defineProps<{ canDefine: boolean }>();

const store = useCardTemplatesStore();
const errorStore = useErrorStore();
const fieldErrors = useFieldErrors(FORM_CTX);

const dialogOpen = ref(false);
/** Set when the dialog is renaming rather than uploading. */
const editing = ref<CardTemplateRow | null>(null);
const replaceTarget = ref<CardTemplateRow | null>(null);
const replaceInput = ref<HTMLInputElement>();

const form = reactive({
    name: '',
    description: '',
    file: null as File | null,
    submitting: false,
});

const dialogTitle = computed(() =>
    editing.value ? 'Rename card template' : 'Upload card template',
);

const inches = (points: number): string => String(fromPoints(points, 'in'));

const cardSize = (t: CardTemplateRow): string =>
    `${inches(t.card_width)} × ${inches(t.card_height)} in`;

const sides = (t: CardTemplateRow): string =>
    t.has_back ? 'Front and back' : 'Single-sided';

function openUpload(): void {
    editing.value = null;
    form.name = '';
    form.description = '';
    form.file = null;
    errorStore.clear(FORM_CTX);
    dialogOpen.value = true;
}

function openRename(t: CardTemplateRow): void {
    editing.value = t;
    form.name = t.name;
    form.description = t.description ?? '';
    form.file = null;
    errorStore.clear(FORM_CTX);
    dialogOpen.value = true;
}

/** Exposed for tests: happy-dom cannot populate a real file input. */
function pickFile(file: File | null): void {
    form.file = file;
}

function onFileChange(event: Event): void {
    pickFile((event.target as HTMLInputElement).files?.[0] ?? null);
}

defineExpose({ pickFile });

async function submit(): Promise<void> {
    form.submitting = true;
    errorStore.clear(FORM_CTX);

    const description =
        form.description.trim() === '' ? null : form.description.trim();

    try {
        if (editing.value) {
            await store.rename(editing.value.id, form.name.trim(), description);
        } else {
            if (form.file === null) {
                errorStore.report({
                    context: FORM_CTX,
                    message: 'Choose a .pptx or .odp file.',
                });

                return;
            }

            await store.upload(form.file, form.name.trim(), description);
        }

        dialogOpen.value = false;
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to save the card template.',
        });
    } finally {
        form.submitting = false;
    }
}

function startReplace(t: CardTemplateRow): void {
    replaceTarget.value = t;
    replaceInput.value?.click();
}

async function onReplaceChosen(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];
    const target = replaceTarget.value;
    (event.target as HTMLInputElement).value = '';

    if (!file || !target) {
        return;
    }

    try {
        await store.replace(target.id, file);
    } catch (e) {
        errorStore.reportFromAxios(e, FORM_CTX, {
            fallback: 'Failed to replace the card template.',
        });
    }
}

async function remove(t: CardTemplateRow): Promise<void> {
    if (
        !window.confirm(
            `Delete the card template "${t.name}"? Trainings using it will have no card until another is chosen.`,
        )
    ) {
        return;
    }

    await store.destroy(t.id);
}
</script>

<template>
    <section class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold">Card templates</h2>
                <p class="text-xs text-muted-foreground">
                    One card per template — slide 1 the front, an optional slide
                    2 the back. The card size comes from the slide.
                </p>
            </div>
            <Button
                v-if="canDefine"
                data-testid="new-template"
                variant="outline"
                size="sm"
                @click="openUpload"
            >
                Upload template
            </Button>
        </div>

        <ErrorBanner :context="FORM_CTX" />

        <p
            v-if="store.library.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No card templates yet — design one card at its real size in
            PowerPoint or Impress, put <code>${user_name}</code>-style fields
            where the details go, and upload it.
        </p>

        <ul
            v-else
            class="divide-y divide-border rounded-md border border-border"
        >
            <li
                v-for="t in store.library"
                :key="t.id"
                class="space-y-1 px-3 py-2 text-sm"
            >
                <div class="flex flex-wrap items-center justify-between gap-3">
                    <div class="min-w-64">
                        <span class="flex items-center gap-2 font-medium">
                            {{ t.name }}
                            <Badge
                                v-if="t.is_system"
                                variant="secondary"
                                class="text-[10px]"
                            >
                                System
                            </Badge>
                            <Badge
                                v-if="t.version > 1"
                                variant="secondary"
                                class="text-[10px]"
                            >
                                v{{ t.version }}
                            </Badge>
                        </span>
                        <span class="text-xs text-muted-foreground">
                            {{ cardSize(t) }} · {{ sides(t) }} ·
                            {{ t.placeholders.length }} merge fields ·
                            {{ t.extension.toUpperCase() }}
                        </span>
                    </div>

                    <div class="flex gap-2">
                        <Button
                            v-if="t.can_edit"
                            :data-testid="`replace-${t.id}`"
                            variant="outline"
                            size="sm"
                            @click="startReplace(t)"
                        >
                            Replace file
                        </Button>
                        <Button
                            v-if="t.can_edit"
                            :data-testid="`rename-${t.id}`"
                            variant="outline"
                            size="sm"
                            @click="openRename(t)"
                        >
                            Rename
                        </Button>
                        <Button
                            v-if="t.can_delete"
                            :data-testid="`delete-${t.id}`"
                            variant="outline"
                            size="sm"
                            @click="remove(t)"
                        >
                            Delete
                        </Button>
                    </div>
                </div>

                <p
                    v-if="t.unsupported_fonts.length"
                    :data-testid="`font-warning-${t.id}`"
                    class="rounded border border-amber-300 bg-amber-50 p-2 text-xs text-amber-900"
                >
                    Not installed on the server:
                    <span class="font-medium">
                        {{ t.unsupported_fonts.join(', ') }}
                    </span>
                    — these are substituted when the PDF is made, which re-flows
                    the text and can throw the card out of alignment. Use a
                    listed font, or check a test print carefully.
                </p>
            </li>
        </ul>

        <!-- Replace picks a file straight from the row, no dialog. -->
        <input
            ref="replaceInput"
            type="file"
            accept=".pptx,.odp"
            class="hidden"
            @change="onReplaceChosen"
        />

        <Dialog v-model:open="dialogOpen">
            <DialogContent class="sm:max-w-lg">
                <form @submit.prevent="submit" class="space-y-4">
                    <DialogHeader>
                        <DialogTitle>{{ dialogTitle }}</DialogTitle>
                        <DialogDescription>
                            A .pptx or .odp file with one or two slides, the
                            slide sized to the card itself.
                        </DialogDescription>
                    </DialogHeader>

                    <div class="grid gap-2">
                        <Label for="ct_name">Name</Label>
                        <Input
                            id="ct_name"
                            v-model="form.name"
                            placeholder="e.g. CPR wallet card"
                            required
                        />
                        <InputError :message="fieldErrors.message('name')" />
                    </div>

                    <div class="grid gap-2">
                        <Label for="ct_description">Description</Label>
                        <Input
                            id="ct_description"
                            v-model="form.description"
                            placeholder="optional"
                        />
                    </div>

                    <div v-if="!editing" class="grid gap-2">
                        <Label for="ct_file">File</Label>
                        <input
                            id="ct_file"
                            type="file"
                            accept=".pptx,.odp"
                            class="text-sm"
                            @change="onFileChange"
                        />
                        <InputError :message="fieldErrors.message('file')" />
                    </div>

                    <DialogFooter>
                        <Button
                            type="button"
                            variant="outline"
                            @click="dialogOpen = false"
                        >
                            Cancel
                        </Button>
                        <Button type="submit" :disabled="form.submitting">
                            Save
                        </Button>
                    </DialogFooter>
                </form>
            </DialogContent>
        </Dialog>
    </section>
</template>
