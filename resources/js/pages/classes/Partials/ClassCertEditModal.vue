<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import MarkdownField from '@/components/MarkdownField.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { optionalNumber } from '@/lib/forms';
import { useClassesStore } from '@/stores/classes';

const props = defineProps<{
    open: boolean;
    classId: string;
    topicId: string | null;
}>();
const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useClassesStore();

const topic = computed(
    () =>
        store.detail[props.classId]?.trainings.find(
            (t) => t.id === props.topicId,
        ) ?? null,
);

const form = reactive({
    cert_title: '',
    cert_text: '',
    cert_code: '',
    lifespan_months: '' as string | number,
});

// (Re)seed the form whenever the modal opens for a (different) topic.
watch(
    () => [props.open, props.topicId] as const,
    ([open]) => {
        if (open && topic.value) {
            form.cert_title = topic.value.cert_title ?? '';
            form.cert_text = topic.value.cert_text ?? '';
            form.cert_code = topic.value.cert_code ?? '';
            form.lifespan_months = topic.value.lifespan_months ?? '';
        }
    },
    { immediate: true },
);

const saving = ref(false);
const actionError = ref<string | null>(null);

async function save(): Promise<void> {
    if (!props.topicId) {
        return;
    }

    saving.value = true;
    actionError.value = null;

    try {
        await store.updateTrainingCert(props.classId, props.topicId, {
            cert_title: form.cert_title.trim() || null,
            cert_text: form.cert_text.trim() || null,
            cert_code: form.cert_code.trim() || null,
            lifespan_months: optionalNumber(form.lifespan_months),
        });
        emit('update:open', false);
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    } finally {
        saving.value = false;
    }
}
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Certificate details</DialogTitle>
                <DialogDescription>
                    Edit the certificate title and text for
                    <span class="font-medium">{{
                        topic?.training_name ?? 'this topic'
                    }}</span>
                    on this class. Seeded from the training; changes here apply
                    to this class only.
                </DialogDescription>
            </DialogHeader>

            <p
                v-if="actionError"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ actionError }}
            </p>

            <form class="space-y-4" @submit.prevent="save">
                <div class="grid gap-2">
                    <Label for="c_cert_title">Certificate title</Label>
                    <Input
                        id="c_cert_title"
                        v-model="form.cert_title"
                        placeholder="e.g. Fall Protection Authorized Person"
                    />
                </div>

                <div class="grid gap-2">
                    <Label for="c_cert_text">Certificate text</Label>
                    <MarkdownField
                        id="c_cert_text"
                        v-model="form.cert_text"
                        :rows="5"
                        placeholder="Satisfies **Cal/OSHA** requirements…"
                    />
                    <p class="text-xs text-muted-foreground">
                        Markdown: blank lines start a new paragraph,
                        <code>**bold**</code> and <code>*italic*</code> are
                        supported. Printed on the certificate body.
                    </p>
                </div>

                <div class="grid grid-cols-2 gap-3">
                    <div class="grid gap-2">
                        <Label for="c_lifespan">Lifespan (months)</Label>
                        <Input
                            id="c_lifespan"
                            type="number"
                            min="0"
                            step="1"
                            v-model="form.lifespan_months"
                            placeholder="e.g. 24"
                        />
                    </div>
                    <div class="grid gap-2">
                        <Label for="c_cert_code">Cert code</Label>
                        <Input
                            id="c_cert_code"
                            v-model="form.cert_code"
                            placeholder="e.g. FPAP"
                        />
                    </div>
                </div>

                <DialogFooter>
                    <Button
                        type="button"
                        variant="outline"
                        @click="emit('update:open', false)"
                    >
                        Cancel
                    </Button>
                    <Button type="submit" :disabled="saving || !topicId">
                        Save certificate
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
