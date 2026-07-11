<script setup lang="ts">
/*
 * The org's generated-documents archive (Phase D2): server-paginated,
 * refetches on the coarse broadcast (which is also how a queued run's
 * completion shows up). Done rows offer the client PDF + the editable
 * source; failed rows surface the error inline.
 */
import { onMounted, watch } from 'vue';
import DataTable from '@/components/DataTable.vue';
import Pagination from '@/components/Pagination.vue';
import { Badge } from '@/components/ui/badge';
import { useServerTable } from '@/composables/useServerTable';
import { useErrorStore } from '@/stores/errors';
import { useGeneratedDocumentsStore } from '@/stores/generatedDocuments';
import type { GeneratedDocumentRow } from '@/stores/generatedDocuments';

const PAGE_CTX = 'page:documents';

const COLUMNS = [
    { key: 'document', label: 'Document', sortable: false },
    { key: 'variation', label: 'Location / Department', sortable: false },
    { key: 'status', label: 'Status', sortable: false },
    { key: 'requested', label: 'Requested', sortable: false },
    { key: 'actions', label: '', sortable: false },
];

const store = useGeneratedDocumentsStore();
const errorStore = useErrorStore();

const table = useServerTable<GeneratedDocumentRow>((params) => store.fetchPage({ ...params }), {
    perPage: 25,
});

watch(
    () => store.revision,
    () => table.refetchSoon(),
);

onMounted(async () => {
    try {
        await table.fetchPage();
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to load generated documents',
        });
    }
});

const STATUS_TONES: Record<string, 'default' | 'secondary' | 'destructive' | 'outline'> = {
    queued: 'secondary',
    processing: 'secondary',
    done: 'default',
    failed: 'destructive',
};

const remove = async (row: GeneratedDocumentRow) => {
    if (!window.confirm(`Delete "${row.filename}" and its files?`)) {
        return;
    }

    try {
        await store.destroy(row.id);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to delete the document',
        });
    }
};

const formatWhen = (iso: string | null): string =>
    iso ? new Date(iso).toLocaleString() : '';
</script>

<template>
    <div class="flex flex-col gap-3">
        <DataTable
            view-id="generated-documents"
            :default-columns="COLUMNS"
            :rows="table.rows.value"
            :sort-key="null"
            sort-dir="desc"
            :row-key="(row) => row.id"
        >
            <template #col-document="{ row }">
                <div class="font-medium">{{ row.template_name ?? '(template removed)' }}</div>
                <div class="font-mono text-xs text-muted-foreground">{{ row.filename }}</div>
            </template>

            <template #col-variation="{ row }">
                <span v-if="row.location || row.department">
                    {{ [row.location, row.department].filter(Boolean).join(' · ') }}
                </span>
                <span v-else class="text-muted-foreground">Org-wide</span>
            </template>

            <template #col-status="{ row }">
                <Badge :variant="STATUS_TONES[row.status] ?? 'secondary'">
                    {{ row.status }}
                </Badge>
                <div v-if="row.status === 'failed' && row.error" class="mt-1 max-w-96 text-xs text-destructive">
                    {{ row.error }}
                </div>
            </template>

            <template #col-requested="{ row }">
                <div class="text-sm">{{ row.requested_by_name ?? '—' }}</div>
                <div class="text-xs text-muted-foreground">{{ formatWhen(row.created_at) }}</div>
            </template>

            <template #col-actions="{ row }">
                <div class="flex items-center justify-end gap-3 text-xs">
                    <template v-if="row.status === 'done'">
                        <a
                            :href="store.downloadUrl(row.id, 'pdf')"
                            class="text-primary hover:underline"
                            data-testid="download-pdf"
                        >
                            PDF
                        </a>
                        <a
                            :href="store.downloadUrl(row.id, 'merged')"
                            class="text-primary hover:underline"
                            data-testid="download-merged"
                        >
                            Editable ({{ row.extension?.toUpperCase() }})
                        </a>
                    </template>
                    <button
                        type="button"
                        class="text-destructive hover:underline"
                        data-testid="delete-generated"
                        @click="remove(row)"
                    >
                        Delete
                    </button>
                </div>
            </template>

            <template #empty>No documents generated yet — pick a template above.</template>
        </DataTable>

        <Pagination
            :page="table.page.value"
            :last-page="table.lastPage.value"
            :total="table.total.value"
            :per-page="table.perPage.value"
            :loading="table.loading.value"
            @update:page="table.setPage"
            @update:per-page="table.setPerPage"
        />
    </div>
</template>
