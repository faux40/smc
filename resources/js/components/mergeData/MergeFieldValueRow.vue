<script setup lang="ts">
/*
 * One merge field's value editor for the current (location, department)
 * variation. The input always edits the variation's OWN row; when the
 * variation has none, the resolved fallback shows as an "Inherited"
 * hint (mirrors the backend ladder via store.resolvedFor). Unset
 * everywhere → the doc generator prints --KEY--, so say exactly that.
 */
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useErrorStore } from '@/stores/errors';
import { useMergeDataStore } from '@/stores/mergeData';
import type { MergeFieldRow, MergeValueContent } from '@/stores/mergeData';

const props = defineProps<{
    field: MergeFieldRow;
    location: string;
    department: string;
    /** Page-level toggle: show definition edit/remove for editable fields. */
    adminActions?: boolean;
}>();

const emit = defineEmits<{
    (e: 'edit'): void;
    (e: 'remove'): void;
}>();

const PAGE_CTX = 'page:document-data';

const store = useMergeDataStore();
const errorStore = useErrorStore();

const row = computed(() =>
    store.rowFor(props.field.id, props.location, props.department),
);
const resolved = computed(() =>
    store.resolvedFor(props.field.id, props.location, props.department),
);

function toText(value: MergeValueContent): string {
    if (value === null) {
        return '';
    }

    return Array.isArray(value) ? value.join('\n') : value;
}

const draft = ref(toText(row.value?.value ?? null));
const saving = ref(false);

// Re-seed when the variation (or a peer-tab refetch) swaps the row out
// from under us — but never while the user is mid-edit on the same row.
watch(
    () => [props.field.id, props.location, props.department, row.value?.id],
    () => {
        draft.value = toText(row.value?.value ?? null);
    },
);

const dirty = computed(() => draft.value !== toText(row.value?.value ?? null));

function parsed(): MergeValueContent {
    if (props.field.type === 'list') {
        return draft.value
            .split('\n')
            .map((s) => s.trim())
            .filter((s) => s !== '');
    }

    return draft.value.trim();
}

const saveDisabled = computed(() => {
    const p = parsed();

    return saving.value || (Array.isArray(p) ? p.length === 0 : p === '');
});

const inheritedHint = computed(() => {
    if (row.value || resolved.value.source === null) {
        return null;
    }

    const from =
        resolved.value.source === 'location'
            ? 'location default'
            : resolved.value.source === 'department'
              ? 'department default'
              : 'org default';

    return { from, text: toText(resolved.value.value) };
});

const unsetEverywhere = computed(
    () => !row.value && resolved.value.source === null,
);

const save = async () => {
    saving.value = true;

    try {
        await store.setValue(
            props.field.id,
            props.location,
            props.department,
            parsed(),
        );
        toast.success(`${props.field.label} saved`);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: `Failed to save ${props.field.label}`,
        });
    } finally {
        saving.value = false;
    }
};

const clear = async () => {
    if (!row.value) {
        return;
    }

    if (!window.confirm(`Clear the stored value for "${props.field.label}"?`)) {
        return;
    }

    try {
        await store.clearValue(row.value.id);
        toast.success(`${props.field.label} cleared`);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: `Failed to clear ${props.field.label}`,
        });
    }
};

const inputId = computed(() => `merge-value-${props.field.id}`);
</script>

<template>
    <div class="grid grid-cols-[minmax(16rem,20rem)_1fr_auto] items-start gap-4 py-3">
        <div>
            <Label :for="inputId" class="font-medium">{{ field.label }}</Label>
            <div class="mt-0.5 font-mono text-xs text-muted-foreground">
                ${{ '{' + field.key + '}' }}
            </div>
            <p v-if="field.help" class="mt-1 text-xs text-muted-foreground">
                {{ field.help }}
            </p>
        </div>

        <div class="min-w-0">
            <textarea
                v-if="field.type === 'multiline' || field.type === 'list'"
                :id="inputId"
                v-model="draft"
                :rows="field.type === 'list' ? 5 : 3"
                class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-xs focus-visible:border-ring focus-visible:ring-[3px] focus-visible:ring-ring/50"
                :placeholder="field.type === 'list' ? 'One item per line' : ''"
            />
            <Input
                v-else
                :id="inputId"
                v-model="draft"
                :type="field.type === 'date' ? 'date' : 'text'"
            />

            <p v-if="field.type === 'list'" class="mt-1 text-xs text-muted-foreground">
                One item per line — templates render these as bulleted lists.
            </p>
            <p v-if="inheritedHint" class="mt-1 text-xs text-muted-foreground">
                Inherited from {{ inheritedHint.from }}:
                <span class="whitespace-pre-line">{{ inheritedHint.text }}</span>
            </p>
            <p v-if="unsetEverywhere" class="mt-1 text-xs text-amber-600">
                Not set — documents will print
                <span class="font-mono">--{{ field.key.toUpperCase() }}--</span>
            </p>
        </div>

        <div class="flex items-center gap-2 pt-0.5">
            <Button
                v-if="dirty"
                size="sm"
                data-testid="save-value"
                :disabled="saveDisabled"
                @click="save"
            >
                {{ saving ? 'Saving…' : 'Save' }}
            </Button>
            <Button
                v-if="row && !dirty"
                size="sm"
                variant="ghost"
                class="text-destructive"
                data-testid="clear-value"
                @click="clear"
            >
                Clear
            </Button>
            <template v-if="adminActions">
                <button
                    v-if="field.can_edit"
                    type="button"
                    class="text-xs text-primary hover:underline"
                    data-testid="edit-field"
                    @click="emit('edit')"
                >
                    Edit field
                </button>
                <button
                    v-if="field.can_delete"
                    type="button"
                    class="text-xs text-destructive hover:underline"
                    data-testid="remove-field"
                    @click="emit('remove')"
                >
                    Remove
                </button>
            </template>
        </div>
    </div>
</template>
