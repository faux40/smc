<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import Heading from '@/components/Heading.vue';
import TagPill from '@/components/TagPill.vue';
import { Button } from '@/components/ui/button';
import TagFormModal from '@/pages/tags/Partials/TagFormModal.vue';
import { page as tagsPage } from '@/routes/tags';
import { useTagsStore } from '@/stores/tags';
import type { TagRow } from '@/stores/tags';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Tags', href: tagsPage() }],
    },
});

const store = useTagsStore();
const page = usePage();

const authUser = computed(
    () =>
        page.props.auth.user as {
            org_id?: string;
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
        } | null,
);
const canManage = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

const modalOpen = ref(false);
const modalMode = ref<'create' | 'edit'>('create');
const editing = ref<TagRow | null>(null);
const error = ref<string | null>(null);

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await store.loadLibrary();
    } catch (e) {
        error.value = (e as Error).message;
    }
});

const sortedLibrary = computed(() =>
    [...store.library].sort((a, b) => a.name.localeCompare(b.name)),
);

const openCreate = () => {
    modalMode.value = 'create';
    editing.value = null;
    modalOpen.value = true;
};

const openEdit = (tag: TagRow) => {
    modalMode.value = 'edit';
    editing.value = tag;
    modalOpen.value = true;
};

const remove = async (tag: TagRow) => {
    const msg =
        tag.attached_count > 0
            ? `Delete tag "${tag.name}"? It's attached to ${tag.attached_count} item(s) — those attachments will be cleared.`
            : `Delete tag "${tag.name}"?`;

    if (!window.confirm(msg)) {
        return;
    }

    error.value = null;

    try {
        await store.destroy(tag.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
};
</script>

<template>
    <Head title="Tags" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Tags"
                description="Org tag library. Attach to users, trainings, and requirements from their respective pages."
            />
            <Button v-if="canManage" @click="openCreate">+ New tag</Button>
        </div>

        <p
            v-if="error"
            class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
        >
            {{ error }}
        </p>

        <div
            v-if="sortedLibrary.length === 0"
            class="rounded border border-dashed border-border p-6 text-center text-sm text-muted-foreground"
        >
            No tags yet.
            <span v-if="canManage">Click "+ New tag" to create one.</span>
        </div>

        <div v-else class="overflow-hidden rounded-md border border-border">
            <table class="min-w-full divide-y divide-border text-sm">
                <thead class="bg-muted/40">
                    <tr>
                        <th class="px-4 py-2 text-left font-medium">Tag</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-border">
                    <tr v-for="tag in sortedLibrary" :key="tag.id">
                        <td class="px-4 py-2">
                            <TagPill
                                :tag="tag"
                                size="md"
                                :count="tag.attached_count"
                            />
                        </td>
                        <td class="space-x-3 px-4 py-2 text-right text-xs">
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-primary hover:underline"
                                @click="openEdit(tag)"
                            >
                                Edit
                            </button>
                            <button
                                v-if="canManage"
                                type="button"
                                class="text-destructive hover:underline"
                                @click="remove(tag)"
                            >
                                Delete
                            </button>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <TagFormModal
            v-model:open="modalOpen"
            :mode="modalMode"
            :target="editing"
        />
    </div>
</template>
