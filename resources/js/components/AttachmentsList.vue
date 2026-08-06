<script setup lang="ts">
/*
 * Reusable polymorphic attachments component.
 *
 *   <AttachmentsList morphable-type="App\Models\User" :morphable-id="user.id" />
 *
 * Routes uploads + deletes through useAttachmentsStore.
 */
import { usePage } from '@inertiajs/vue3';
import { MoreHorizontal } from 'lucide-vue-next';
import { computed, onMounted, ref } from 'vue';
import AttachmentUploadModal from '@/components/AttachmentUploadModal.vue';
import AttachmentViewer from '@/components/AttachmentViewer.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { useAttachmentsStore } from '@/stores/attachments';
import type { AttachmentRow } from '@/stores/attachments';

const props = withDefaults(
    defineProps<{
        morphableType: string;
        morphableId: string | number;
        /**
         * Whether the viewer may add files. Defaults true because for most
         * parents uploading is open to any org member; a host whose parent is
         * role-managed (a Training) passes false, and the list still renders
         * read-only. The server re-checks either way — this only removes an
         * affordance that would 403.
         */
        canUpload?: boolean;
    }>(),
    { canUpload: true },
);

const store = useAttachmentsStore();
const page = usePage();

const morphable = computed(() => ({
    type: props.morphableType,
    id: String(props.morphableId),
}));

const attachments = computed<AttachmentRow[]>(() =>
    store.listFor(morphable.value),
);

const error = ref<string | null>(null);
const uploadOpen = ref(false);

const viewerOpen = ref(false);
const activeAttachment = ref<AttachmentRow | null>(null);

const openViewer = (a: AttachmentRow) => {
    activeAttachment.value = a;
    viewerOpen.value = true;
};

onMounted(async () => {
    const orgId = (page.props.auth.user as { org_id?: string } | null)?.org_id;

    if (orgId) {
        store.subscribe(orgId);
    }

    try {
        await store.load(morphable.value);
    } catch (e) {
        error.value = (e as Error).message;
    }
});

// Delete flows through an "are you sure" popup rather than a native confirm.
const confirmOpen = ref(false);
const pendingDelete = ref<AttachmentRow | null>(null);
const deleting = ref(false);

const requestDelete = (a: AttachmentRow) => {
    pendingDelete.value = a;
    confirmOpen.value = true;
};

const confirmDelete = async () => {
    const target = pendingDelete.value;

    if (!target) {
        return;
    }

    deleting.value = true;
    error.value = null;

    try {
        await store.destroy(target.id);
        confirmOpen.value = false;
        pendingDelete.value = null;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        deleting.value = false;
    }
};

const formatSize = (bytes: number | null): string => {
    if (bytes === null) {
        return '';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
};
</script>

<template>
    <section class="space-y-4">
        <p
            v-if="error"
            class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
        >
            {{ error }}
        </p>

        <div class="flex items-center gap-3">
            <Button v-if="props.canUpload" @click="uploadOpen = true">
                Upload files
            </Button>
            <span
                v-if="attachments.length === 0"
                class="text-sm text-muted-foreground"
            >
                No attachments yet.
            </span>
        </div>

        <ul v-if="attachments.length > 0" class="space-y-2">
            <li
                v-for="a in attachments"
                :key="a.id"
                class="flex items-center gap-3 rounded border border-border p-3 text-sm"
            >
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <button
                            type="button"
                            class="truncate text-left font-medium text-primary hover:underline"
                            @click="openViewer(a)"
                        >
                            {{ a.filename }}
                        </button>
                        <span
                            v-if="a.type"
                            class="shrink-0 rounded bg-muted px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide text-muted-foreground"
                        >
                            {{ a.type }}
                        </span>
                    </div>
                    <p
                        v-if="a.description"
                        class="truncate text-xs text-muted-foreground"
                        :title="a.description"
                    >
                        {{ a.description }}
                    </p>
                    <div class="text-xs text-muted-foreground">
                        <span v-if="a.mime">{{ a.mime }}</span>
                        <span v-if="a.size !== null">
                            · {{ formatSize(a.size) }}</span
                        >
                        <span v-if="a.uploaded_by_name">
                            · uploaded by {{ a.uploaded_by_name }}
                        </span>
                        <span v-if="a.created_at"> · {{ a.created_at }}</span>
                    </div>
                </div>
                <DropdownMenu>
                    <DropdownMenuTrigger as-child>
                        <button
                            type="button"
                            class="inline-flex size-7 items-center justify-center rounded text-muted-foreground hover:bg-muted hover:text-foreground"
                            :aria-label="`Actions for ${a.filename}`"
                        >
                            <MoreHorizontal class="size-4" />
                        </button>
                    </DropdownMenuTrigger>
                    <DropdownMenuContent align="end">
                        <DropdownMenuItem as-child>
                            <a :href="store.downloadUrl(a.id)">Download</a>
                        </DropdownMenuItem>
                        <DropdownMenuItem
                            v-if="a.can_delete"
                            class="text-destructive focus:text-destructive"
                            @select="requestDelete(a)"
                        >
                            Delete
                        </DropdownMenuItem>
                    </DropdownMenuContent>
                </DropdownMenu>
            </li>
        </ul>

        <AttachmentUploadModal
            v-model:open="uploadOpen"
            :morphable-type="morphableType"
            :morphable-id="morphableId"
        />

        <AttachmentViewer
            v-model:open="viewerOpen"
            :attachment="activeAttachment"
        />

        <Dialog v-model:open="confirmOpen">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete file?</DialogTitle>
                    <DialogDescription>
                        Are you sure you want to delete “{{
                            pendingDelete?.filename
                        }}”? This can’t be undone.
                    </DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button
                        variant="outline"
                        :disabled="deleting"
                        @click="confirmOpen = false"
                    >
                        Cancel
                    </Button>
                    <Button
                        variant="destructive"
                        :disabled="deleting"
                        data-testid="attachment-delete-confirm"
                        @click="confirmDelete"
                    >
                        {{ deleting ? 'Deleting…' : 'Delete' }}
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </section>
</template>
