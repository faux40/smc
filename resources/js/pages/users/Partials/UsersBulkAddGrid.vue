<script setup lang="ts">
/*
 * BULK USER ADD — a spreadsheet-style grid for adding many users at once.
 * Sits above the users table (the table stays visible/filterable so dupes
 * are easy to spot). Supports Excel/Sheets paste, live duplicate + required
 * checks, and per-row server-error mapping after a best-effort submit.
 *
 * Pure logic lives in @/lib/bulkUsers; data I/O goes through the users store
 * (bulkCreate). Created users stream back into the table via the org-channel
 * UserRegistered subscription.
 */
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import {
    applyPaste,
    emptyRow,
    GRID_COLUMNS,
    isRowEmpty,
    parsePastedGrid,
    validateGrid,
} from '@/lib/bulkUsers';
import type { GridRow } from '@/lib/bulkUsers';
import { useUsersStore } from '@/stores/users';
import type { BulkRowResult, BulkUserRow, FieldOptions } from '@/stores/users';

const props = defineProps<{
    existingEmails: string[];
    roles: string[];
    supervisors: Array<{ id: string; name: string }>;
    fieldOptions: FieldOptions;
}>();

const emit = defineEmits<{ (e: 'done'): void }>();

const store = useUsersStore();

const INITIAL_ROWS = 3;
const rows = ref<GridRow[]>(
    Array.from({ length: INITIAL_ROWS }, () => emptyRow()),
);

const submitting = ref(false);
const summary = ref<{ created: number; skipped: number } | null>(null);
// Server-side per-row errors, keyed by the row index we submitted.
const serverErrors = ref<
    Record<number, Partial<Record<keyof GridRow, string>>>
>({});

const existingSet = computed(
    () => new Set(props.existingEmails.map((e) => e.toLowerCase())),
);

const clientErrors = computed(() =>
    validateGrid(rows.value, existingSet.value),
);

const filledCount = computed(
    () => rows.value.filter((r) => !isRowEmpty(r)).length,
);

const hasBlockingErrors = computed(
    () => Object.keys(clientErrors.value).length > 0,
);

function errorFor(index: number, col: keyof GridRow): string | undefined {
    return clientErrors.value[index]?.[col] ?? serverErrors.value[index]?.[col];
}

function addRows(n = 1): void {
    for (let i = 0; i < n; i++) {
        rows.value.push(emptyRow());
    }
}

function removeRow(index: number): void {
    rows.value.splice(index, 1);

    if (rows.value.length === 0) {
        rows.value.push(emptyRow());
    }
}

// Multi-cell paste (tabs / newlines) fans out across rows×cols from the
// focused cell; a single value pastes normally (let the browser handle it).
function onPaste(
    event: ClipboardEvent,
    rowIndex: number,
    col: keyof GridRow,
): void {
    const text = event.clipboardData?.getData('text/plain') ?? '';

    if (!text.includes('\t') && !text.includes('\n')) {
        return;
    }

    event.preventDefault();
    const grid = parsePastedGrid(text);
    const startCol = GRID_COLUMNS.indexOf(col);
    rows.value = applyPaste(rows.value, grid, rowIndex, startCol);
}

async function submit(): Promise<void> {
    serverErrors.value = {};
    summary.value = null;

    // Submit only touched rows, but remember each one's original grid index
    // so server errors map back to the right row.
    const optionalCols: Array<keyof GridRow> = GRID_COLUMNS.filter(
        (c) => c !== 'role' && c !== 'f_name' && c !== 'l_name',
    );

    const payload: Array<{ index: number; data: Record<string, string> }> = [];
    rows.value.forEach((row, index) => {
        if (isRowEmpty(row)) {
            return;
        }

        // f_name/l_name are guaranteed present at submit (hasBlockingErrors
        // gates it); optional cells are omitted when blank so the server
        // sees null rather than ''.
        const data: Record<string, string> = {
            f_name: row.f_name.trim(),
            l_name: row.l_name.trim(),
            role: row.role,
        };
        optionalCols.forEach((c) => {
            const value = row[c].trim();

            if (value !== '') {
                data[c] = value;
            }
        });
        payload.push({ index, data });
    });

    if (payload.length === 0 || hasBlockingErrors.value) {
        return;
    }

    submitting.value = true;

    try {
        const res = await store.bulkCreate(
            payload.map((p) => p.data) as unknown as BulkUserRow[],
        );
        summary.value = { created: res.created, skipped: res.skipped };

        // Map positional server results back to original grid indexes.
        const createdIdx = new Set<number>();
        const errorsByOrigin: Record<
            number,
            Partial<Record<keyof GridRow, string>>
        > = {};
        res.results.forEach((r: BulkRowResult) => {
            const origin = payload[r.index]?.index;

            if (origin === undefined) {
                return;
            }

            if (r.status === 'created') {
                createdIdx.add(origin);
            } else if (r.errors) {
                errorsByOrigin[origin] = r.errors as Partial<
                    Record<keyof GridRow, string>
                >;
            }
        });

        // Drop created rows; keep skipped rows so they can be fixed + resent.
        // Re-key errors to the NEW indexes since removing rows shifts them.
        const kept: GridRow[] = [];
        const remapped: Record<
            number,
            Partial<Record<keyof GridRow, string>>
        > = {};
        rows.value.forEach((row, oldIndex) => {
            if (createdIdx.has(oldIndex)) {
                return;
            }

            if (errorsByOrigin[oldIndex]) {
                remapped[kept.length] = errorsByOrigin[oldIndex];
            }

            kept.push(row);
        });

        rows.value = kept.length > 0 ? kept : [emptyRow()];
        serverErrors.value = remapped;
        emit('done');
    } finally {
        submitting.value = false;
    }
}

const COLS: Array<{ key: keyof GridRow; label: string; type?: string }> = [
    { key: 'f_name', label: 'First *' },
    { key: 'l_name', label: 'Last *' },
    { key: 'email', label: 'Email' },
    { key: 'role', label: 'Role' },
    { key: 'employee_number', label: 'Emp #' },
    { key: 'job_title', label: 'Job title' },
    { key: 'department', label: 'Department' },
    { key: 'location', label: 'Location' },
    { key: 'supervisor_id', label: 'Supervisor' },
    { key: 'start_date', label: 'Start', type: 'date' },
];
</script>

<template>
    <section
        class="space-y-3 rounded-md border border-border p-4"
        data-testid="bulk-add-grid"
    >
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-semibold">Bulk add users</h2>
            <span class="text-xs text-muted-foreground">
                Paste from a spreadsheet, or type rows. {{ filledCount }} to
                add.
            </span>
        </div>

        <div class="overflow-x-auto">
            <table class="w-full border-collapse text-sm">
                <thead>
                    <tr class="text-left text-xs text-muted-foreground">
                        <th
                            v-for="c in COLS"
                            :key="c.key"
                            class="px-1 py-1 font-medium"
                        >
                            {{ c.label }}
                        </th>
                        <th class="px-1 py-1"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="(row, i) in rows"
                        :key="i"
                        data-testid="bulk-row"
                    >
                        <td
                            v-for="c in COLS"
                            :key="c.key"
                            class="p-0.5 align-top"
                        >
                            <select
                                v-if="c.key === 'role'"
                                v-model="row.role"
                                class="w-full rounded border border-border bg-background px-1.5 py-1"
                            >
                                <option v-for="r in roles" :key="r" :value="r">
                                    {{ r }}
                                </option>
                            </select>
                            <select
                                v-else-if="c.key === 'supervisor_id'"
                                v-model="row.supervisor_id"
                                class="w-full rounded border border-border bg-background px-1.5 py-1"
                            >
                                <option value="">—</option>
                                <option
                                    v-for="s in supervisors"
                                    :key="s.id"
                                    :value="s.id"
                                >
                                    {{ s.name }}
                                </option>
                            </select>
                            <template v-else>
                                <input
                                    v-model="row[c.key]"
                                    :type="c.type ?? 'text'"
                                    :list="
                                        [
                                            'department',
                                            'location',
                                            'job_title',
                                        ].includes(c.key)
                                            ? `opts-${c.key}`
                                            : undefined
                                    "
                                    :aria-label="`${c.label} row ${i + 1}`"
                                    class="w-full rounded border px-1.5 py-1"
                                    :class="
                                        errorFor(i, c.key)
                                            ? 'border-red-400 bg-red-50'
                                            : 'border-border bg-background'
                                    "
                                    @paste="onPaste($event, i, c.key)"
                                />
                                <p
                                    v-if="errorFor(i, c.key)"
                                    class="px-1 text-[10px] text-red-600"
                                >
                                    {{ errorFor(i, c.key) }}
                                </p>
                            </template>
                        </td>
                        <td class="p-0.5 align-top">
                            <button
                                type="button"
                                class="px-1.5 py-1 text-xs text-muted-foreground hover:text-red-600"
                                :aria-label="`Remove row ${i + 1}`"
                                @click="removeRow(i)"
                            >
                                ✕
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <datalist id="opts-department">
            <option v-for="o in fieldOptions.department" :key="o" :value="o" />
        </datalist>
        <datalist id="opts-location">
            <option v-for="o in fieldOptions.location" :key="o" :value="o" />
        </datalist>
        <datalist id="opts-job_title">
            <option v-for="o in fieldOptions.job_title" :key="o" :value="o" />
        </datalist>

        <div class="flex items-center gap-2">
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="addRows(1)"
                >+ Row</Button
            >
            <Button
                type="button"
                variant="outline"
                size="sm"
                @click="addRows(5)"
                >+ 5</Button
            >
            <div class="flex-1"></div>
            <span
                v-if="summary"
                data-testid="bulk-summary"
                class="text-xs text-muted-foreground"
            >
                Added {{ summary.created
                }}{{
                    summary.skipped
                        ? `, ${summary.skipped} skipped (fix below)`
                        : ''
                }}.
            </span>
            <Button
                type="button"
                size="sm"
                :disabled="submitting || filledCount === 0 || hasBlockingErrors"
                @click="submit"
            >
                {{ submitting ? 'Adding…' : `Add ${filledCount} user(s)` }}
            </Button>
        </div>
    </section>
</template>
