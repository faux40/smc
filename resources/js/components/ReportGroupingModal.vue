<script setup lang="ts">
/*
 * Interstitial "configure grouping" dialog for the completion report export.
 * Pick any of the grouping dimensions (training, status, user, location,
 * department) via checkboxes; checked dimensions float to the top in
 * precedence order with up/down controls so the user sets the nesting order.
 * Leaving all unchecked produces a flat (ungrouped) report.
 *
 * The host passes `baseHref` (the export URL carrying the current filters +
 * visible columns). The "Generate report" link appends the chosen dimensions
 * as ordered `group_by[]` params — the backend (ReportGrouping) nests in that
 * order. Grouping applies to both formats: the PDF renders nested group
 * bands, the CSV interleaves one-cell group-label rows with the data rows
 * (see ReportsController::writeGroupedCsvRows). The on-screen table is
 * unaffected either way.
 *
 * "Download CSV" reuses the exact same `finalHref` (filters + columns +
 * group_by) and just appends `format=csv`, so it always exports precisely
 * what "Generate report" (PDF) would — never a second source of truth for
 * which filters/columns/grouping apply.
 */
import { ArrowDown, ArrowUp } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';
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
    /** Export URL with filters + columns already applied (no group_by). */
    baseHref: string;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

// Keys mirror ReportGrouping::LABELS on the backend.
const OPTIONS = [
    { key: 'training', label: 'Training' },
    { key: 'status', label: 'Status' },
    { key: 'user', label: 'User' },
    { key: 'location', label: 'Location' },
    { key: 'department', label: 'Department' },
];

// Ordered list of checked keys; order is the nesting precedence.
const selected = ref<string[]>([]);

// Reset every time the dialog opens, so a prior selection doesn't linger.
watch(
    () => props.open,
    (open) => {
        if (open) {
            selected.value = [];
        }
    },
);

// Checked options first (in precedence order), then the rest in canonical order.
const ordered = computed(() => {
    const chosen = selected.value
        .map((key) => OPTIONS.find((o) => o.key === key))
        .filter((o): o is (typeof OPTIONS)[number] => Boolean(o));
    const rest = OPTIONS.filter((o) => !selected.value.includes(o.key));

    return [...chosen, ...rest];
});

function isChecked(key: string): boolean {
    return selected.value.includes(key);
}

function toggle(key: string): void {
    selected.value = isChecked(key)
        ? selected.value.filter((k) => k !== key)
        : [...selected.value, key];
}

function move(key: string, dir: 'up' | 'down'): void {
    const keys = selected.value.slice();
    const i = keys.indexOf(key);
    const j = dir === 'up' ? i - 1 : i + 1;

    if (i === -1 || j < 0 || j >= keys.length) {
        return;
    }

    [keys[i], keys[j]] = [keys[j], keys[i]];
    selected.value = keys;
}

const finalHref = computed(() => {
    if (selected.value.length === 0) {
        return props.baseHref;
    }

    const sep = props.baseHref.includes('?') ? '&' : '?';
    const qs = selected.value
        .map((k) => `group_by%5B%5D=${encodeURIComponent(k)}`)
        .join('&');

    return `${props.baseHref}${sep}${qs}`;
});

// Same href, format=csv appended — never build the query string twice.
const csvHref = computed(() => {
    const sep = finalHref.value.includes('?') ? '&' : '?';

    return `${finalHref.value}${sep}format=csv`;
});
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-xl">
            <DialogHeader>
                <DialogTitle>Group the report</DialogTitle>
                <DialogDescription>
                    Check the dimensions to group by. Checked dimensions nest in
                    the order shown — use the arrows to reorder. Leave all
                    unchecked for a flat report.
                </DialogDescription>
            </DialogHeader>

            <ul class="divide-y divide-border rounded-md border border-border">
                <li
                    v-for="opt in ordered"
                    :key="opt.key"
                    class="flex items-center gap-3 px-3 py-2"
                >
                    <button
                        type="button"
                        class="flex flex-1 items-center gap-3 text-left text-sm"
                        :data-testid="`group-toggle-${opt.key}`"
                        @click="toggle(opt.key)"
                    >
                        <Checkbox
                            :model-value="isChecked(opt.key)"
                            class="pointer-events-none"
                        />
                        <span
                            v-if="isChecked(opt.key)"
                            class="inline-flex size-5 items-center justify-center rounded bg-muted text-xs font-medium"
                        >
                            {{ selected.indexOf(opt.key) + 1 }}
                        </span>
                        <span class="font-medium">{{ opt.label }}</span>
                    </button>

                    <div
                        v-if="isChecked(opt.key)"
                        class="flex items-center gap-1"
                    >
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            :disabled="selected.indexOf(opt.key) === 0"
                            :data-testid="`group-up-${opt.key}`"
                            :aria-label="`Move ${opt.label} up`"
                            @click="move(opt.key, 'up')"
                        >
                            <ArrowUp class="size-4" />
                        </Button>
                        <Button
                            type="button"
                            variant="ghost"
                            size="icon"
                            class="size-7"
                            :disabled="
                                selected.indexOf(opt.key) ===
                                selected.length - 1
                            "
                            :data-testid="`group-down-${opt.key}`"
                            :aria-label="`Move ${opt.label} down`"
                            @click="move(opt.key, 'down')"
                        >
                            <ArrowDown class="size-4" />
                        </Button>
                    </div>
                    <span v-else class="text-xs text-muted-foreground"
                        >not grouped</span
                    >
                </li>
            </ul>

            <DialogFooter>
                <Button variant="outline" @click="emit('update:open', false)">
                    Cancel
                </Button>
                <Button variant="secondary" as-child>
                    <a
                        :href="csvHref"
                        target="_blank"
                        rel="noopener"
                        data-testid="export-completion-report-csv"
                        @click="emit('update:open', false)"
                    >
                        Download CSV
                    </a>
                </Button>
                <Button as-child>
                    <a
                        :href="finalHref"
                        target="_blank"
                        rel="noopener"
                        data-testid="export-completion-report"
                        @click="emit('update:open', false)"
                    >
                        Generate report
                    </a>
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
