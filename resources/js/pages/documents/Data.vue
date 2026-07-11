<script setup lang="ts">
/*
 * Document data (Phase D1) — org merge-field values, grouped by
 * field_group, editable per (location, department) variation.
 *
 * Managers enter values; Admins additionally define org fields (system
 * fields are read-only definitions from the universal template set).
 * The variation bar switches which override set the inputs edit —
 * org-wide defaults when both boxes are blank.
 */
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import ComboboxInput from '@/components/ComboboxInput.vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import MergeFieldFormModal from '@/components/mergeData/MergeFieldFormModal.vue';
import MergeFieldValueRow from '@/components/mergeData/MergeFieldValueRow.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { data as documentDataRoute } from '@/routes/documents';
import { useErrorStore } from '@/stores/errors';
import { useMergeDataStore } from '@/stores/mergeData';
import type { MergeFieldRow } from '@/stores/mergeData';
import { useUsersStore } from '@/stores/users';

const PAGE_CTX = 'page:document-data';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Document data', href: documentDataRoute() }],
    },
});

const store = useMergeDataStore();
const usersStore = useUsersStore();
const errorStore = useErrorStore();
const page = usePage();

const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
            isManager?: boolean;
        } | null,
);
const canDefine = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

const loading = ref(true);
const location = ref('');
const department = ref('');

const fieldModalOpen = ref(false);
const editingField = ref<MergeFieldRow | null>(null);

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([store.load(), usersStore.loadFieldOptions()]);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to load document data',
        });
    } finally {
        loading.value = false;
    }
});

// Variation suggestions: the org's people fields plus anything an
// override row already uses (overrides can reference retired sites).
const locationSuggestions = computed(() => {
    const fromValues = store.values.map((v) => v.location).filter((l) => l !== '');

    return [...new Set([...usersStore.fieldOptions.location, ...fromValues])];
});
const departmentSuggestions = computed(() => {
    const fromValues = store.values.map((v) => v.department).filter((d) => d !== '');

    return [...new Set([...usersStore.fieldOptions.department, ...fromValues])];
});

const editingDefaults = computed(
    () => location.value === '' && department.value === '',
);

function setCount(fields: MergeFieldRow[]): number {
    return fields.filter(
        (f) => store.resolvedFor(f.id, location.value, department.value).source !== null,
    ).length;
}

const openCreateField = () => {
    editingField.value = null;
    fieldModalOpen.value = true;
};

const openEditField = (field: MergeFieldRow) => {
    editingField.value = field;
    fieldModalOpen.value = true;
};

const removeField = async (field: MergeFieldRow) => {
    if (
        !window.confirm(
            `Remove the field "${field.label}" and all its stored values? Templates using \${${field.key}} will print --${field.key.toUpperCase()}--.`,
        )
    ) {
        return;
    }

    try {
        await store.destroyField(field.id);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to remove field',
        });
    }
};
</script>

<template>
    <Head title="Document data" />

    <h1 class="sr-only">Document data</h1>

    <div class="flex flex-col space-y-6">
        <div class="flex items-start justify-between gap-4">
            <Heading
                variant="small"
                title="Document data"
                description="Values merged into ${key} tokens when documents are generated. Blank fields print a visible --PLACEHOLDER-- so gaps are obvious."
            />
            <Button
                v-if="canDefine"
                data-testid="add-field"
                @click="openCreateField"
            >
                + Add field
            </Button>
        </div>

        <ErrorBanner :context="PAGE_CTX" />

        <!-- Variation bar: blank = org-wide defaults; picking a location
             and/or department edits that override set. -->
        <div
            class="flex flex-wrap items-end gap-4 rounded-md border border-border bg-muted/30 p-4"
        >
            <div class="grid w-64 gap-2" data-testid="variation-location">
                <Label for="variation-location-input">Location</Label>
                <ComboboxInput
                    id="variation-location-input"
                    v-model="location"
                    :suggestions="locationSuggestions"
                    placeholder="All locations"
                />
            </div>
            <div class="grid w-64 gap-2" data-testid="variation-department">
                <Label for="variation-department-input">Department</Label>
                <ComboboxInput
                    id="variation-department-input"
                    v-model="department"
                    :suggestions="departmentSuggestions"
                    placeholder="All departments"
                />
            </div>
            <div class="pb-2 text-sm text-muted-foreground">
                <template v-if="editingDefaults">
                    Editing <strong>org-wide defaults</strong>.
                </template>
                <template v-else>
                    Editing overrides for
                    <strong>{{ [location, department].filter(Boolean).join(' · ') }}</strong>
                    — blank fields inherit the org default.
                    <button
                        type="button"
                        class="ml-2 text-primary hover:underline"
                        @click="
                            location = '';
                            department = '';
                        "
                    >
                        Back to defaults
                    </button>
                </template>
            </div>
        </div>

        <AsyncState :loading="loading" :empty="store.fields.length === 0">
            <template #empty>
                <div
                    class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No merge fields yet. System fields arrive with universal
                    document templates<span v-if="canDefine">
                        , or click "+ Add field" to define your own</span
                    >.
                </div>
            </template>

            <section
                v-for="group in store.groupedFields"
                :key="group.group ?? '(ungrouped)'"
                class="rounded-md border border-border"
            >
                <header
                    class="flex items-center justify-between border-b border-border bg-muted/40 px-4 py-2"
                >
                    <h2 class="text-sm font-semibold">
                        {{ group.group ?? 'Other' }}
                    </h2>
                    <span class="text-xs text-muted-foreground">
                        {{ setCount(group.fields) }} of
                        {{ group.fields.length }} set
                    </span>
                </header>
                <div class="divide-y divide-border px-4">
                    <MergeFieldValueRow
                        v-for="field in group.fields"
                        :key="field.id"
                        :field="field"
                        :location="location"
                        :department="department"
                        :admin-actions="canDefine"
                        @edit="openEditField(field)"
                        @remove="removeField(field)"
                    />
                </div>
            </section>
        </AsyncState>

        <MergeFieldFormModal
            v-model:open="fieldModalOpen"
            :editing="editingField"
        />
    </div>
</template>
