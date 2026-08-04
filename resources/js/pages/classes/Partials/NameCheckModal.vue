<script setup lang="ts">
/*
 * "Name check sheet" — configure and open the class's spelling-proof sheet.
 *
 * The sheet lists every person's name exactly as it will print on a
 * certificate or a wallet card (`full_name` — prefix, first, middle, last,
 * suffix), alphabetically by last/first/middle, so a typo is caught before a
 * sheet of purchased card stock is committed.
 *
 * Reached from the class Actions menu rather than the Documents row, which is
 * why it needs a dialog at all: a dropdown item cannot host a column picker.
 * The picker drives BOTH outputs — "Open PDF" hands the columns to the parent
 * (which opens the in-app viewer) and "Download CSV" appends the same ones —
 * so the two can never disagree about what the sheet contains. It defaults to
 * the name alone, which is the proofing case; everything else is opt-in.
 */
import { computed, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';

const props = defineProps<{
    open: boolean;
    classId: string;
    /** Drives the audience explainer — the server applies the same rule. */
    completed: boolean;
}>();

const emit = defineEmits<{
    (e: 'update:open', v: boolean): void;
    (e: 'view', columns: string[]): void;
}>();

/** Mirrors ClassNameCheck::COLUMNS on the backend, in the same order. */
const COLUMNS = [
    { key: 'full_name', label: 'Full name (as printed)' },
    { key: 'employee_number', label: 'Employee #' },
    { key: 'job_title', label: 'Job title' },
    { key: 'department', label: 'Department' },
    { key: 'location', label: 'Location' },
] as const;

/** Locked on: the sheet is about this column. Mirrors ClassNameCheck::ALWAYS. */
const LOCKED = ['full_name'];

const picked = ref<string[]>([...LOCKED]);

const isChecked = (key: string): boolean => picked.value.includes(key);
const isLocked = (key: string): boolean => LOCKED.includes(key);

function toggle(key: string): void {
    if (isLocked(key)) {
        return;
    }

    picked.value = isChecked(key)
        ? picked.value.filter((k) => k !== key)
        : [...picked.value, key];
}

/*
 * Catalog order, not click order — the columns should land the same way every
 * time regardless of which box was ticked first, and the server renders them
 * in the order it receives.
 */
const orderedColumns = computed(() =>
    COLUMNS.map((c) => c.key).filter((k) => picked.value.includes(k)),
);

const baseHref = computed(() => `/api/classes/${props.classId}/name-check`);

/*
 * Only non-default columns go on the URL: with just the name ticked the link
 * stays clean, and the server's own default already resolves to the same set.
 */
const columnQuery = computed(() =>
    orderedColumns.value
        .filter((k) => !isLocked(k))
        .map((k) => `columns%5B%5D=${encodeURIComponent(k)}`)
        .join('&'),
);

const csvHref = computed(() => {
    const parts = [columnQuery.value, 'format=csv'].filter(Boolean);

    return `${baseHref.value}?${parts.join('&')}`;
});

function openPdf(): void {
    emit('view', orderedColumns.value);
    emit('update:open', false);
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Name check sheet</DialogTitle>
                <DialogDescription>
                    Every name exactly as it will print on a certificate or
                    card, sorted by last, first, middle — proof-read it before
                    committing a sheet of stock.
                    <template v-if="completed">
                        This class is closed, so it lists only the people
                        <strong>awarded credit</strong>.
                    </template>
                    <template v-else>
                        This class is still open, so it lists
                        <strong>everyone on the roster</strong>.
                    </template>
                </DialogDescription>
            </DialogHeader>

            <div class="space-y-2">
                <p class="text-sm font-medium">Columns</p>
                <ul
                    class="divide-y divide-border rounded-md border border-border"
                >
                    <li
                        v-for="col in COLUMNS"
                        :key="col.key"
                        class="flex items-center gap-3 px-3 py-2"
                    >
                        <button
                            type="button"
                            class="flex flex-1 items-center gap-3 text-left text-sm"
                            :class="
                                isLocked(col.key)
                                    ? 'cursor-default text-muted-foreground'
                                    : ''
                            "
                            :data-testid="`column-toggle-${col.key}`"
                            @click="toggle(col.key)"
                        >
                            <Checkbox
                                :model-value="isChecked(col.key)"
                                :disabled="isLocked(col.key)"
                                class="pointer-events-none"
                            />
                            <span>{{ col.label }}</span>
                            <span
                                v-if="isLocked(col.key)"
                                class="text-xs text-muted-foreground"
                            >
                                always included
                            </span>
                        </button>
                    </li>
                </ul>
            </div>

            <DialogFooter>
                <a
                    :href="csvHref"
                    class="inline-flex h-9 items-center rounded-md border border-border px-4 text-sm font-medium hover:bg-accent"
                    data-testid="name-check-csv"
                >
                    Download CSV
                </a>
                <Button
                    type="button"
                    data-testid="name-check-pdf"
                    @click="openPdf"
                >
                    Open PDF
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
