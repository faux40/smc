<script setup lang="ts">
/*
 * Reusable polymorphic comments component.
 *
 *   <CommentsList morphable-type="App\Models\User" :morphable-id="user.id" />
 *
 * Owns no fetch / axios calls — talks only to useCommentsStore.
 */
import { computed, onMounted, ref } from 'vue';
import { usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { useCommentsStore, type CommentRow } from '@/stores/comments';

const props = defineProps<{
    morphableType: string;
    morphableId: string | number;
}>();

const store = useCommentsStore();
const page = usePage();

const morphable = computed(() => ({
    type: props.morphableType,
    id: String(props.morphableId),
}));

const comments = computed<CommentRow[]>(() => store.listFor(morphable.value));

const newBody = ref('');
const submitting = ref(false);
const error = ref<string | null>(null);

const editingId = ref<string | null>(null);
const editingBody = ref('');

onMounted(async () => {
    const orgId = (page.props.auth.user as { org_id?: string } | null)?.org_id;
    if (orgId) store.subscribe(orgId);
    try {
        await store.load(morphable.value);
    } catch (e) {
        error.value = (e as Error).message;
    }
});

const submit = async () => {
    if (!newBody.value.trim()) return;
    submitting.value = true;
    error.value = null;
    try {
        await store.create(morphable.value, newBody.value.trim());
        newBody.value = '';
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        submitting.value = false;
    }
};

const startEdit = (c: CommentRow) => {
    editingId.value = c.id;
    editingBody.value = c.body;
};

const cancelEdit = () => {
    editingId.value = null;
    editingBody.value = '';
};

const saveEdit = async (id: string) => {
    if (!editingBody.value.trim()) return;
    error.value = null;
    try {
        await store.update(id, editingBody.value.trim());
        cancelEdit();
    } catch (e) {
        error.value = (e as Error).message;
    }
};

const remove = async (c: CommentRow) => {
    if (!window.confirm('Delete this comment?')) return;
    try {
        await store.destroy(c.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
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

        <div
            v-if="comments.length === 0"
            class="text-sm text-muted-foreground"
        >
            No comments yet.
        </div>
        <ul v-else class="space-y-2">
            <li
                v-for="c in comments"
                :key="c.id"
                class="rounded border border-border p-3 text-sm"
            >
                <template v-if="editingId === c.id">
                    <textarea
                        v-model="editingBody"
                        rows="2"
                        class="w-full rounded border border-input bg-background p-2"
                    ></textarea>
                    <div class="mt-2 flex justify-end gap-2">
                        <button
                            type="button"
                            class="text-xs text-muted-foreground"
                            @click="cancelEdit"
                        >
                            Cancel
                        </button>
                        <button
                            type="button"
                            class="rounded bg-primary px-2 py-1 text-xs text-primary-foreground disabled:opacity-50"
                            :disabled="!editingBody.trim()"
                            @click="saveEdit(c.id)"
                        >
                            Save
                        </button>
                    </div>
                </template>
                <template v-else>
                    <div>{{ c.body }}</div>
                    <div
                        class="mt-1 flex items-center gap-2 text-xs text-muted-foreground"
                    >
                        <span>{{ c.author_name ?? 'Unknown' }}</span>
                        <span v-if="c.created_at">·</span>
                        <span v-if="c.created_at">{{ c.created_at }}</span>
                        <div class="ml-auto flex gap-2">
                            <button
                                v-if="c.can_edit"
                                type="button"
                                class="text-primary hover:underline"
                                @click="startEdit(c)"
                            >
                                Edit
                            </button>
                            <button
                                v-if="c.can_delete"
                                type="button"
                                class="text-destructive hover:underline"
                                @click="remove(c)"
                            >
                                Delete
                            </button>
                        </div>
                    </div>
                </template>
            </li>
        </ul>

        <form @submit.prevent="submit" class="space-y-2">
            <textarea
                v-model="newBody"
                rows="2"
                placeholder="Add a comment…"
                class="w-full rounded border border-input bg-background p-2 text-sm"
            ></textarea>
            <div class="flex justify-end">
                <Button :disabled="submitting || !newBody.trim()">
                    {{ submitting ? 'Posting…' : 'Post' }}
                </Button>
            </div>
        </form>
    </section>
</template>
