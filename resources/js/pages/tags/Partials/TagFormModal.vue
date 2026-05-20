<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import ColorPicker from '@/components/ColorPicker.vue';
import InputError from '@/components/InputError.vue';
import TagPill from '@/components/TagPill.vue';
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
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { useTagsStore } from '@/stores/tags';
import type { TagRow } from '@/stores/tags';

type Mode = 'create' | 'edit';

const props = defineProps<{
    open: boolean;
    mode: Mode;
    target?: TagRow | null;
}>();

const emit = defineEmits<{ (e: 'update:open', v: boolean): void }>();

const store = useTagsStore();

const DEFAULT_COLOR = '#6b7280';
const DEFAULT_FONT_COLOR = '#ffffff';

const form = reactive({
    name: '',
    color: DEFAULT_COLOR,
    hasColor: true,
    fontColor: DEFAULT_FONT_COLOR,
    hasFontColor: false,
});
const errors = ref<Record<string, string>>({});
const submitting = ref(false);

const isEdit = computed(() => props.mode === 'edit');
const title = computed(() => (isEdit.value ? 'Edit tag' : 'New tag'));

// Preview pill stays in sync with form state so the admin can see what
// the chip looks like before saving.
const previewTag = computed<TagRow>(() => ({
    id: props.target?.id ?? 'preview',
    name: form.name.trim() === '' ? 'Tag preview' : form.name,
    color: form.hasColor ? form.color : null,
    font_color: form.hasFontColor ? form.fontColor : null,
    attached_count: props.target?.attached_count ?? 0,
}));

watch(
    () => props.open,
    (open) => {
        if (!open) {
            return;
        }

        errors.value = {};

        if (isEdit.value && props.target) {
            form.name = props.target.name;
            form.hasColor = props.target.color !== null;
            form.color = props.target.color ?? DEFAULT_COLOR;
            form.hasFontColor = props.target.font_color !== null;
            form.fontColor = props.target.font_color ?? DEFAULT_FONT_COLOR;
        } else {
            form.name = '';
            form.hasColor = true;
            form.color = DEFAULT_COLOR;
            form.hasFontColor = false;
            form.fontColor = DEFAULT_FONT_COLOR;
        }
    },
);

const submit = async () => {
    submitting.value = true;
    errors.value = {};

    try {
        const color = form.hasColor ? form.color : null;
        const fontColor = form.hasFontColor ? form.fontColor : null;

        if (isEdit.value && props.target) {
            await store.rename(props.target.id, form.name, color, fontColor);
        } else {
            await store.create(form.name, color, fontColor);
        }

        emit('update:open', false);
    } catch (e: unknown) {
        const err = e as {
            response?: { data?: { errors?: Record<string, string[]> } };
        };
        const errs = err.response?.data?.errors;

        if (errs) {
            errors.value = Object.fromEntries(
                Object.entries(errs).map(([k, v]) => [k, v[0] ?? '']),
            );
        }
    } finally {
        submitting.value = false;
    }
};
</script>

<template>
    <Dialog :open="open" @update:open="(v) => emit('update:open', v)">
        <DialogContent class="sm:max-w-md">
            <form @submit.prevent="submit" class="space-y-4">
                <DialogHeader>
                    <DialogTitle>{{ title }}</DialogTitle>
                    <DialogDescription>
                        Tags are org-scoped and attach to users, trainings, and
                        requirements. Soft-deleted tags also drop their
                        attachments.
                    </DialogDescription>
                </DialogHeader>

                <div class="grid gap-2">
                    <Label for="t_name">Name</Label>
                    <Input id="t_name" v-model="form.name" required autofocus />
                    <InputError :message="errors.name" />
                </div>

                <div class="grid gap-2">
                    <Label class="flex items-center gap-2">
                        <Checkbox v-model="form.hasColor" />
                        <span>Color</span>
                    </Label>
                    <div class="flex items-center gap-3">
                        <ColorPicker
                            v-model="form.color"
                            :disabled="!form.hasColor"
                            aria-label="Tag color"
                        />
                        <span class="text-xs text-muted-foreground">
                            {{
                                form.hasColor
                                    ? form.color
                                    : 'No color (neutral)'
                            }}
                        </span>
                    </div>
                    <InputError :message="errors.color" />
                </div>

                <div class="grid gap-2">
                    <Label class="flex items-center gap-2">
                        <Checkbox v-model="form.hasFontColor" />
                        <span>Font color (override)</span>
                    </Label>
                    <div class="flex items-center gap-3">
                        <ColorPicker
                            v-model="form.fontColor"
                            :disabled="!form.hasFontColor"
                            aria-label="Tag font color"
                        />
                        <span class="text-xs text-muted-foreground">
                            {{
                                form.hasFontColor
                                    ? form.fontColor
                                    : 'Derived from color'
                            }}
                        </span>
                    </div>
                    <InputError :message="errors.font_color" />
                </div>

                <div class="grid gap-2">
                    <Label>Preview</Label>
                    <div>
                        <TagPill :tag="previewTag" size="md" />
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
                    <Button type="submit" :disabled="submitting">
                        {{ submitting ? 'Saving…' : 'Save' }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>
