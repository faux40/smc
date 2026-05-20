<script setup lang="ts">
/*
 * Tag-driven bulk assignment (Phase 13.1 flagship).
 *
 * Pick a tag → the page fetches the cross-product preview (users tagged
 * with X × requirements tagged with X) plus the existing assignment
 * pairs inside it. A matrix shows every pair with a per-cell checkbox;
 * existing pairs render as locked. The shared timing form below applies
 * to every selected pair. Submit POSTs the picked pairs + timing; the
 * server skips any that the preview already had marked as existing.
 */
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import TagPill from '@/components/TagPill.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { realtimeTabId } from '@/echo';
import { useStdFrequenciesStore } from '@/stores/stdFrequencies';
import { useTagsStore } from '@/stores/tags';
import type { TagRow } from '@/stores/tags';

interface PreviewUser {
    id: string;
    f_name: string | null;
    l_name: string | null;
    email: string | null;
}

interface PreviewRequirement {
    id: string;
    name: string;
    description: string | null;
}

interface PreviewResponse {
    tag: TagRow;
    users: PreviewUser[];
    requirements: PreviewRequirement[];
    existing_pairs: Array<{ user_id: string; requirement_id: string }>;
}

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Workflows', href: '#' },
            { title: 'Bulk assignment', href: '#' },
        ],
    },
});

const tagsStore = useTagsStore();
const frequencies = useStdFrequenciesStore();
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
const canUse = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
);

const selectedTagId = ref<string>('');
const preview = ref<PreviewResponse | null>(null);
const loadingPreview = ref(false);
const previewError = ref<string | null>(null);

// selection[userId][requirementId] = boolean. New pairs default to true
// (checked); existing pairs are not in this map — rendered as locked.
const selection = reactive<Record<string, Record<string, boolean>>>({});

const form = reactive({
    initial_only: false,
    repeating: true,
    std_freq_id: null as string | null,
    as_needed: false,
    start_date: new Date().toISOString().slice(0, 10),
    end_date: '' as string,
});
const errors = ref<Record<string, string>>({});
const submitting = ref(false);
const submitResult = ref<{ created: number; skipped: number } | null>(null);

onMounted(async () => {
    if (authUser.value?.org_id) {
        tagsStore.subscribe(authUser.value.org_id);
    }

    try {
        await Promise.all([tagsStore.loadLibrary(), frequencies.load()]);
    } catch (e) {
        previewError.value = (e as Error).message;
    }
});

const existingKeys = computed(() => {
    if (!preview.value) {
        return new Set<string>();
    }

    return new Set(
        preview.value.existing_pairs.map(
            (p) => `${p.user_id}|${p.requirement_id}`,
        ),
    );
});

watch(selectedTagId, async (tagId) => {
    preview.value = null;
    Object.keys(selection).forEach((k) => delete selection[k]);
    submitResult.value = null;
    previewError.value = null;

    if (!tagId) {
        return;
    }

    loadingPreview.value = true;

    try {
        const { data } = await axios.get<PreviewResponse>(
            '/api/bulk-assignments/preview',
            {
                params: { tag_id: tagId },
                headers: defaultHeaders(),
            },
        );
        preview.value = data;
        // Default every non-existing pair to selected.
        const existing = new Set(
            data.existing_pairs.map((p) => `${p.user_id}|${p.requirement_id}`),
        );
        data.users.forEach((u) => {
            selection[u.id] = {};
            data.requirements.forEach((r) => {
                const key = `${u.id}|${r.id}`;

                if (!existing.has(key)) {
                    selection[u.id][r.id] = true;
                }
            });
        });
    } catch (e) {
        previewError.value = (e as Error).message;
    } finally {
        loadingPreview.value = false;
    }
});

const pickedPairs = computed(() => {
    if (!preview.value) {
        return [] as Array<{ user_id: string; requirement_id: string }>;
    }

    const out: Array<{ user_id: string; requirement_id: string }> = [];
    preview.value.users.forEach((u) => {
        preview.value!.requirements.forEach((r) => {
            const key = `${u.id}|${r.id}`;

            if (existingKeys.value.has(key)) {
                return;
            } // never re-create existing

            if (selection[u.id]?.[r.id]) {
                out.push({ user_id: u.id, requirement_id: r.id });
            }
        });
    });

    return out;
});

const totalCells = computed(() =>
    preview.value
        ? preview.value.users.length * preview.value.requirements.length
        : 0,
);

const hasPreview = computed(
    () =>
        preview.value !== null &&
        preview.value.users.length > 0 &&
        preview.value.requirements.length > 0,
);

const fullName = (u: PreviewUser): string => {
    const parts = [u.f_name, u.l_name].filter(Boolean);

    return parts.length ? parts.join(' ') : (u.email ?? 'Unnamed');
};

const selectAll = () => {
    if (!preview.value) {
        return;
    }

    preview.value.users.forEach((u) => {
        preview.value!.requirements.forEach((r) => {
            if (!existingKeys.value.has(`${u.id}|${r.id}`)) {
                selection[u.id] = selection[u.id] ?? {};
                selection[u.id][r.id] = true;
            }
        });
    });
};

const selectNone = () => {
    if (!preview.value) {
        return;
    }

    preview.value.users.forEach((u) => {
        if (!selection[u.id]) {
            return;
        }

        Object.keys(selection[u.id]).forEach((k) => {
            selection[u.id][k] = false;
        });
    });
};

const submit = async () => {
    if (pickedPairs.value.length === 0) {
        return;
    }

    submitting.value = true;
    errors.value = {};
    submitResult.value = null;

    try {
        const payload = {
            pairs: pickedPairs.value,
            initial_only: form.initial_only,
            repeating: form.repeating,
            std_freq_id: form.repeating ? form.std_freq_id : null,
            as_needed: form.as_needed,
            start_date: form.start_date,
            end_date: form.end_date === '' ? null : form.end_date,
        };
        const { data } = await axios.post<{
            created_count: number;
            skipped_count: number;
        }>('/api/bulk-assignments', payload, { headers: defaultHeaders() });
        submitResult.value = {
            created: data.created_count,
            skipped: data.skipped_count,
        };

        // Re-fetch the preview so the matrix reflects the newly-locked cells.
        if (selectedTagId.value) {
            const refreshed = await axios.get<PreviewResponse>(
                '/api/bulk-assignments/preview',
                {
                    params: { tag_id: selectedTagId.value },
                    headers: defaultHeaders(),
                },
            );
            preview.value = refreshed.data;
            // Reset selection for the still-unassigned pairs only.
            Object.keys(selection).forEach((k) => delete selection[k]);
            const existing = new Set(
                refreshed.data.existing_pairs.map(
                    (p) => `${p.user_id}|${p.requirement_id}`,
                ),
            );
            refreshed.data.users.forEach((u) => {
                selection[u.id] = {};
                refreshed.data.requirements.forEach((r) => {
                    const key = `${u.id}|${r.id}`;

                    if (!existing.has(key)) {
                        selection[u.id][r.id] = false;
                    }
                });
            });
        }
    } catch (e: unknown) {
        const err = e as {
            response?: {
                data?: { errors?: Record<string, string[]>; message?: string };
            };
        };
        const errs = err.response?.data?.errors;

        if (errs) {
            errors.value = Object.fromEntries(
                Object.entries(errs).map(([k, v]) => [k, v[0] ?? '']),
            );
        }

        previewError.value =
            err.response?.data?.message ?? (e as Error).message;
    } finally {
        submitting.value = false;
    }
};

function defaultHeaders(): Record<string, string> {
    const csrf = document.querySelector<HTMLMetaElement>(
        'meta[name="csrf-token"]',
    )?.content;

    return {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
        'X-Origin-Tab': realtimeTabId(),
        ...(csrf ? { 'X-CSRF-TOKEN': csrf } : {}),
    };
}
</script>

<template>
    <Head title="Bulk assignment" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Bulk assignment"
            description="Pick a tag to see every (user × requirement) pair that shares it. Untick cells you don't want, set timing once, and assign in one go."
        />

        <p
            v-if="!canUse"
            class="rounded bg-amber-50 p-3 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100"
        >
            You need the Manager role or higher to use this workflow.
        </p>

        <template v-if="canUse">
            <div class="flex items-end gap-3">
                <div class="grid gap-2">
                    <Label for="tag_id">Tag</Label>
                    <Select v-model="selectedTagId">
                        <SelectTrigger id="tag_id" class="w-72">
                            <SelectValue placeholder="Pick a tag…" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="t in tagsStore.library"
                                :key="t.id"
                                :value="t.id"
                            >
                                <TagPill :tag="t" size="sm" />
                            </SelectItem>
                        </SelectContent>
                    </Select>
                </div>
                <TagPill v-if="preview" :tag="preview.tag" size="md" />
            </div>

            <p
                v-if="previewError"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ previewError }}
            </p>

            <p
                v-if="submitResult"
                class="rounded bg-emerald-50 p-2 text-sm text-emerald-800 dark:bg-emerald-900/30 dark:text-emerald-200"
            >
                Created {{ submitResult.created }} assignment(s);
                {{ submitResult.skipped }} skipped (already existed).
            </p>

            <p v-if="loadingPreview" class="text-sm text-muted-foreground">
                Loading…
            </p>

            <template v-if="preview && !loadingPreview">
                <p
                    v-if="!hasPreview"
                    class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
                >
                    No users or requirements share this tag yet. Attach the tag
                    on the Users / Requirements pages, then come back.
                </p>

                <template v-else>
                    <div class="flex items-center gap-2 text-sm">
                        <span class="text-muted-foreground">
                            {{ totalCells }} cell{{
                                totalCells === 1 ? '' : 's'
                            }}
                            ({{ preview.existing_pairs.length }} already
                            assigned)
                        </span>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="selectAll"
                        >
                            Select all available
                        </Button>
                        <Button
                            type="button"
                            variant="outline"
                            size="sm"
                            @click="selectNone"
                        >
                            Clear selection
                        </Button>
                    </div>

                    <div
                        class="overflow-x-auto rounded-md border border-border"
                    >
                        <table
                            class="min-w-full divide-y divide-border text-sm"
                        >
                            <thead class="bg-muted/40">
                                <tr>
                                    <th
                                        class="sticky left-0 z-10 bg-muted/40 px-4 py-2 text-left font-medium"
                                    >
                                        User \ Requirement
                                    </th>
                                    <th
                                        v-for="r in preview.requirements"
                                        :key="r.id"
                                        class="px-3 py-2 text-left font-medium"
                                        :title="r.description ?? ''"
                                    >
                                        {{ r.name }}
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-border">
                                <tr v-for="u in preview.users" :key="u.id">
                                    <td
                                        class="sticky left-0 z-10 bg-background px-4 py-2 font-medium"
                                    >
                                        {{ fullName(u) }}
                                        <div
                                            v-if="u.email"
                                            class="text-xs text-muted-foreground"
                                        >
                                            {{ u.email }}
                                        </div>
                                    </td>
                                    <td
                                        v-for="r in preview.requirements"
                                        :key="r.id"
                                        class="px-3 py-2 text-center"
                                    >
                                        <span
                                            v-if="
                                                existingKeys.has(
                                                    `${u.id}|${r.id}`,
                                                )
                                            "
                                            class="inline-block rounded bg-muted px-2 py-0.5 text-xs text-muted-foreground"
                                            title="Already assigned"
                                        >
                                            ✓ assigned
                                        </span>
                                        <Checkbox
                                            v-else
                                            v-model="selection[u.id][r.id]"
                                        />
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>

                    <div class="grid gap-4 rounded-md border border-border p-4">
                        <p class="text-sm font-medium">
                            Timing — applied to every new assignment
                        </p>

                        <div class="flex flex-wrap gap-4 text-sm">
                            <label class="flex items-center gap-2">
                                <Checkbox v-model="form.initial_only" />
                                Initial-only
                            </label>
                            <label class="flex items-center gap-2">
                                <Checkbox v-model="form.repeating" />
                                Repeating
                            </label>
                            <label class="flex items-center gap-2">
                                <Checkbox v-model="form.as_needed" />
                                As-needed
                            </label>
                        </div>
                        <InputError :message="errors.initial_only" />
                        <InputError :message="errors.repeating" />

                        <div v-if="form.repeating" class="grid max-w-xs gap-2">
                            <Label for="freq">Frequency</Label>
                            <Select v-model="form.std_freq_id">
                                <SelectTrigger id="freq">
                                    <SelectValue
                                        placeholder="Pick a frequency…"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="f in frequencies.library"
                                        :key="f.id"
                                        :value="f.id"
                                    >
                                        {{ f.name }} ({{ f.repeat_days }} day{{
                                            f.repeat_days === 1 ? '' : 's'
                                        }})
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                            <InputError :message="errors.std_freq_id" />
                        </div>

                        <div class="grid max-w-md grid-cols-2 gap-3">
                            <div class="grid gap-2">
                                <Label for="start_date">Start date</Label>
                                <Input
                                    id="start_date"
                                    type="date"
                                    v-model="form.start_date"
                                />
                                <InputError :message="errors.start_date" />
                            </div>
                            <div class="grid gap-2">
                                <Label for="end_date"
                                    >End date (optional)</Label
                                >
                                <Input
                                    id="end_date"
                                    type="date"
                                    v-model="form.end_date"
                                />
                                <InputError :message="errors.end_date" />
                            </div>
                        </div>
                    </div>

                    <div class="flex items-center justify-end gap-3">
                        <span class="text-sm text-muted-foreground">
                            {{ pickedPairs.length }} pair(s) ready to assign
                        </span>
                        <Button
                            type="button"
                            :disabled="submitting || pickedPairs.length === 0"
                            @click="submit"
                        >
                            {{
                                submitting
                                    ? 'Assigning…'
                                    : `Create ${pickedPairs.length} assignment(s)`
                            }}
                        </Button>
                    </div>
                </template>
            </template>
        </template>
    </div>
</template>
