<script setup lang="ts">
/*
 * Reusable polymorphic tags component.
 *
 * Drop on any page that consumes a HasTags model:
 *   <TagsField
 *     morphable-type="App\Models\User"
 *     :morphable-id="user.id"
 *     :initial-tag-ids="user.tag_ids"
 *     :can-manage-library="isAdmin"
 *   />
 *
 * The component is a thin view over useTagsStore — it never fetches or
 * mutates directly. The store subscribes to peer broadcasts on first
 * mount so this tab + every other tab stay in sync.
 */
import { computed, onMounted, ref, watch } from 'vue';
import { usePage } from '@inertiajs/vue3';
import TagPill from '@/components/TagPill.vue';
import { useTagsStore, type TagRow } from '@/stores/tags';

type Mode = 'left' | 'right';
type Size = 'sm' | 'md';

const props = withDefaults(
    defineProps<{
        morphableType: string;
        morphableId: string | number;
        initialTagIds?: string[];
        canManageLibrary?: boolean;
        readonly?: boolean;
        align?: Mode;
        size?: Size;
    }>(),
    {
        initialTagIds: () => [],
        canManageLibrary: false,
        readonly: false,
        align: 'left',
        size: 'sm',
    },
);

const store = useTagsStore();
const page = usePage();

const morphable = computed(() => ({
    type: props.morphableType,
    id: String(props.morphableId),
}));

const attached = computed<TagRow[]>(() => store.attachedTagsFor(morphable.value));
const attachedIds = computed(() => new Set(attached.value.map((t) => t.id)));

const query = ref('');
const picking = ref(false);
const error = ref<string | null>(null);
const submitting = ref(false);

onMounted(async () => {
    store.setAttached(morphable.value, props.initialTagIds);
    const orgId = (page.props.auth.user as { org_id?: string } | null)?.org_id;
    if (orgId) store.subscribe(orgId);
    if (!props.readonly) {
        try {
            await store.loadLibrary();
        } catch {
            // Library load failure is non-fatal; attached pills still render.
        }
    }
});

watch(
    () => props.initialTagIds,
    (v) => store.setAttached(morphable.value, v),
);

const filteredLibrary = computed(() => {
    const q = query.value.trim().toLowerCase();
    return store.library
        .filter((t) => !attachedIds.value.has(t.id))
        .filter((t) => (q ? t.name.toLowerCase().includes(q) : true));
});

const exactMatch = computed(() => {
    const q = query.value.trim().toLowerCase();
    if (!q) return false;
    return store.library.some((t) => t.name.toLowerCase() === q);
});

const showCreateRow = computed(
    () => props.canManageLibrary && query.value.trim().length > 0 && !exactMatch.value,
);

const rowAlignClass = computed(() =>
    props.align === 'right' ? 'justify-end' : 'justify-start',
);

const attach = async (tag: TagRow) => {
    error.value = null;
    submitting.value = true;
    try {
        await store.attach(morphable.value, tag.id);
        query.value = '';
        picking.value = false;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        submitting.value = false;
    }
};

const detach = async (tag: TagRow) => {
    error.value = null;
    try {
        await store.detach(morphable.value, tag.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
};

const createAndAttach = async () => {
    const name = query.value.trim();
    if (!name) return;
    error.value = null;
    submitting.value = true;
    try {
        const tag = await store.create(name);
        await store.attach(morphable.value, tag.id);
        query.value = '';
        picking.value = false;
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        submitting.value = false;
    }
};

const openPicker = () => {
    picking.value = true;
};

const closePicker = () => {
    picking.value = false;
    query.value = '';
};
</script>

<template>
    <div class="space-y-2">
        <p
            v-if="error"
            class="rounded bg-red-50 p-2 text-xs text-red-800 dark:bg-red-900/30 dark:text-red-200"
        >
            {{ error }}
        </p>

        <div class="flex flex-wrap items-center gap-2" :class="rowAlignClass">
            <TagPill
                v-for="tag in attached"
                :key="tag.id"
                :tag="tag"
                :size="size"
            >
                <button
                    v-if="!readonly"
                    type="button"
                    class="ml-0.5 leading-none opacity-70 hover:opacity-100"
                    :aria-label="`Remove ${tag.name}`"
                    @click="detach(tag)"
                >
                    &times;
                </button>
            </TagPill>

            <span
                v-if="attached.length === 0 && readonly"
                class="text-xs text-muted-foreground"
            >
                No tags.
            </span>

            <button
                v-if="!readonly && !picking"
                type="button"
                class="rounded-full border border-dashed border-gray-300 px-2 py-0.5 text-xs text-gray-600 hover:border-gray-400 hover:text-gray-800 dark:border-gray-600 dark:text-gray-300 dark:hover:border-gray-500 dark:hover:text-gray-100"
                @click="openPicker"
            >
                + Add tag
            </button>
        </div>

        <div v-if="picking && !readonly" class="relative max-w-xs">
            <input
                v-model="query"
                type="text"
                placeholder="Find or create…"
                class="w-full rounded border border-input bg-background px-3 py-1.5 text-sm"
                @keydown.escape="closePicker"
                autofocus
            />
            <ul
                class="absolute z-10 mt-1 max-h-48 w-full overflow-auto rounded border border-input bg-popover shadow"
            >
                <li
                    v-for="tag in filteredLibrary"
                    :key="tag.id"
                    class="cursor-pointer px-3 py-1.5 text-sm hover:bg-accent"
                    @click="attach(tag)"
                >
                    <TagPill :tag="tag" :size="size" />
                </li>
                <li
                    v-if="
                        filteredLibrary.length === 0 &&
                        !showCreateRow &&
                        query.trim().length > 0
                    "
                    class="px-3 py-1.5 text-xs text-muted-foreground"
                >
                    No matches.
                </li>
                <li
                    v-if="showCreateRow"
                    class="cursor-pointer border-t border-border px-3 py-1.5 text-sm text-primary hover:bg-accent"
                    @click="createAndAttach"
                >
                    + Create "{{ query.trim() }}"
                </li>
                <li
                    v-if="
                        store.library.length === 0 &&
                        !showCreateRow &&
                        query.trim().length === 0
                    "
                    class="px-3 py-1.5 text-xs text-muted-foreground"
                >
                    No tags yet.
                </li>
            </ul>
            <button
                type="button"
                class="absolute right-2 top-1/2 -translate-y-1/2 text-xs text-muted-foreground hover:text-foreground"
                :disabled="submitting"
                @click="closePicker"
            >
                Cancel
            </button>
        </div>
    </div>
</template>
