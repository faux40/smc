<script setup lang="ts">
/*
 * Reusable polymorphic attachments component.
 *
 *   <AttachmentsList morphable-type="App\Models\User" :morphable-id="user.id" />
 *
 * Routes uploads + deletes through useAttachmentsStore.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import { Button } from '@/components/ui/button';
import { useAttachmentsStore } from '@/stores/attachments';
import type { AttachmentRow } from '@/stores/attachments';

const props = defineProps<{
    morphableType: string;
    morphableId: string | number;
}>();

const store = useAttachmentsStore();
const page = usePage();

const morphable = computed(() => ({
    type: props.morphableType,
    id: String(props.morphableId),
}));

const attachments = computed<AttachmentRow[]>(() =>
    store.listFor(morphable.value),
);

const fileInput = ref<HTMLInputElement | null>(null);
const submitting = ref(false);
const error = ref<string | null>(null);

onMounted(async () => {
    const orgId = (page.props.auth.user as { org_id?: string } | null)?.org_id;

    if (orgId) {
        store.subscribe(orgId);
    }

    try {
        await store.load(morphable.value);
    } catch (e) {
        error.value = (e as Error).message;
    }
});

const triggerPicker = () => {
    fileInput.value?.click();
};

const onPicked = async (event: Event) => {
    const target = event.target as HTMLInputElement;
    const file = target.files?.[0];

    if (!file) {
        return;
    }

    submitting.value = true;
    error.value = null;

    try {
        await store.upload(morphable.value, file);
        target.value = '';
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        submitting.value = false;
    }
};

const remove = async (a: AttachmentRow) => {
    if (!window.confirm(`Delete ${a.filename}?`)) {
        return;
    }

    error.value = null;

    try {
        await store.destroy(a.id);
    } catch (e) {
        error.value = (e as Error).message;
    }
};

const formatSize = (bytes: number | null): string => {
    if (bytes === null) {
        return '';
    }

    if (bytes < 1024) {
        return `${bytes} B`;
    }

    if (bytes < 1024 * 1024) {
        return `${(bytes / 1024).toFixed(1)} KB`;
    }

    return `${(bytes / 1024 / 1024).toFixed(1)} MB`;
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

        <div class="flex items-center gap-3">
            <Button :disabled="submitting" @click="triggerPicker">
                {{ submitting ? 'Uploading…' : 'Upload file' }}
            </Button>
            <input
                ref="fileInput"
                type="file"
                class="hidden"
                @change="onPicked"
            />
            <span
                v-if="attachments.length === 0"
                class="text-sm text-muted-foreground"
            >
                No attachments yet.
            </span>
        </div>

        <ul v-if="attachments.length > 0" class="space-y-2">
            <li
                v-for="a in attachments"
                :key="a.id"
                class="flex items-center gap-3 rounded border border-border p-3 text-sm"
            >
                <div class="flex-1">
                    <a
                        :href="store.downloadUrl(a.id)"
                        class="font-medium text-primary hover:underline"
                    >
                        {{ a.filename }}
                    </a>
                    <div class="text-xs text-muted-foreground">
                        <span v-if="a.mime">{{ a.mime }}</span>
                        <span v-if="a.size !== null">
                            · {{ formatSize(a.size) }}</span
                        >
                        <span v-if="a.uploaded_by_name">
                            · uploaded by {{ a.uploaded_by_name }}
                        </span>
                        <span v-if="a.created_at"> · {{ a.created_at }}</span>
                    </div>
                </div>
                <button
                    v-if="a.can_delete"
                    type="button"
                    class="text-xs text-destructive hover:underline"
                    @click="remove(a)"
                >
                    Delete
                </button>
            </li>
        </ul>
    </section>
</template>
