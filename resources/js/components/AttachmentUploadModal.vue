<script setup lang="ts">
/*
 * Upload attachments with optional per-file Type + Description. Drag-and-drop
 * (or click to pick) one or many files; each gets its own editable row,
 * pre-filled (duplicated) from the first row. Type/description are optional —
 * Save is the primary action so you can just drop files and confirm.
 *
 * Uploads route through the attachments store (one request per file).
 */
import { computed, ref, watch } from 'vue';
import AttachmentInfoFields from '@/components/AttachmentInfoFields.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { useAttachmentsStore } from '@/stores/attachments';

const props = defineProps<{
    open: boolean;
    morphableType: string;
    morphableId: string | number;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useAttachmentsStore();

interface Row {
    key: number;
    file: File;
    type: string;
    description: string;
}

const rows = ref<Row[]>([]);
const submitting = ref(false);
const error = ref<string | null>(null);
const dragging = ref(false);
const fileInput = ref<HTMLInputElement | null>(null);
let nextKey = 0;

const morphable = computed(() => ({
    type: props.morphableType,
    id: String(props.morphableId),
}));

watch(
    () => props.open,
    (open) => {
        if (open) {
            rows.value = [];
            error.value = null;
            dragging.value = false;
        }
    },
);

/** Append files as rows, duplicating the first row's type/description. */
function addFiles(files: FileList | File[]): void {
    const seed = rows.value[0];

    for (const file of Array.from(files)) {
        rows.value.push({
            key: nextKey++,
            file,
            type: seed?.type ?? '',
            description: seed?.description ?? '',
        });
    }
}

function onDrop(event: DragEvent): void {
    dragging.value = false;

    if (event.dataTransfer?.files?.length) {
        addFiles(event.dataTransfer.files);
    }
}

function onPicked(event: Event): void {
    const target = event.target as HTMLInputElement;

    if (target.files?.length) {
        addFiles(target.files);
    }

    target.value = '';
}

function removeRow(key: number): void {
    rows.value = rows.value.filter((r) => r.key !== key);
}

/** Copy the first row's type + description down to every row. */
function copyFirstToAll(): void {
    const first = rows.value[0];

    if (!first) {
        return;
    }

    for (const r of rows.value) {
        r.type = first.type;
        r.description = first.description;
    }
}

async function submit(): Promise<void> {
    if (rows.value.length === 0) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        for (const r of rows.value) {
            await store.upload(morphable.value, r.file, {
                type: r.type.trim() || null,
                description: r.description.trim() || null,
            });
        }

        emit('update:open', false);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        submitting.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] w-[92vw] overflow-y-auto sm:max-w-4xl">
            <DialogHeader>
                <DialogTitle>Upload files</DialogTitle>
                <DialogDescription>
                    Drag &amp; drop or choose files. Type and description are
                    optional — set them per file (the first row’s values carry
                    to new files). Then Save.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="error"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ error }}
            </p>

            <!-- Drop zone -->
            <div
                class="rounded-lg border-2 border-dashed p-6 text-center text-sm transition-colors"
                :class="
                    dragging
                        ? 'border-primary bg-primary/5'
                        : 'border-border text-muted-foreground'
                "
                data-testid="attachment-dropzone"
                @dragover.prevent="dragging = true"
                @dragenter.prevent="dragging = true"
                @dragleave.prevent="dragging = false"
                @drop.prevent="onDrop"
            >
                Drag files here, or
                <button
                    type="button"
                    class="font-medium text-primary hover:underline"
                    @click="fileInput?.click()"
                >
                    browse
                </button>
                <input
                    ref="fileInput"
                    type="file"
                    multiple
                    class="hidden"
                    @change="onPicked"
                />
            </div>

            <ul v-if="rows.length" class="space-y-3">
                <li
                    v-for="(r, i) in rows"
                    :key="r.key"
                    data-testid="upload-row"
                    class="space-y-2 rounded border border-border p-3"
                >
                    <div class="flex items-center justify-between gap-2">
                        <span class="truncate text-sm font-medium">
                            {{ r.file.name }}
                        </span>
                        <div class="flex items-center gap-2">
                            <button
                                v-if="i === 0 && rows.length > 1"
                                type="button"
                                class="text-xs text-primary hover:underline"
                                @click="copyFirstToAll"
                            >
                                Copy to all
                            </button>
                            <button
                                type="button"
                                class="text-xs text-destructive hover:underline"
                                :aria-label="`Remove ${r.file.name}`"
                                @click="removeRow(r.key)"
                            >
                                Remove
                            </button>
                        </div>
                    </div>
                    <AttachmentInfoFields
                        v-model:type="r.type"
                        v-model:description="r.description"
                        :id-prefix="`upload_${r.key}`"
                    />
                </li>
            </ul>

            <DialogFooter>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="submitting"
                    @click="emit('update:open', false)"
                >
                    Cancel
                </Button>
                <Button
                    type="button"
                    :disabled="submitting || rows.length === 0"
                    data-testid="upload-save"
                    @click="submit"
                >
                    {{
                        submitting
                            ? 'Uploading…'
                            : `Save${rows.length ? ` (${rows.length})` : ''}`
                    }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
