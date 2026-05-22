<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import axios from 'axios';
import { computed, onMounted, ref } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
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
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import { page as classesPage } from '@/routes/classes';
import { useClassesStore } from '@/stores/classes';
import { useTrainingsStore } from '@/stores/trainings';

const props = defineProps<{ classId: string }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Classes', href: classesPage() }],
    },
});

interface PickerUser {
    id: string;
    f_name: string;
    l_name: string;
    email: string | null;
}

const store = useClassesStore();
const trainings = useTrainingsStore();
const page = usePage();
const orgId = computed(
    () => (page.props.auth.user as { org_id?: string } | null)?.org_id ?? null,
);

const loading = ref(true);
const error = ref<string | null>(null);
const detail = computed(() => store.detail[props.classId] ?? null);

const userPicker = ref<PickerUser[]>([]);
const editOpen = ref(false);
const attachTrainingId = ref('');
const attachHours = ref('');
const enrollUserId = ref('');
const actionError = ref<string | null>(null);

function headers(): Record<string, string> {
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

onMounted(async () => {
    if (orgId.value) {
        store.subscribe(orgId.value);
    }

    try {
        await Promise.all([
            store.loadDetail(props.classId),
            trainings.load(),
            loadUsers(),
        ]);
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});

async function loadUsers(): Promise<void> {
    const { data } = await axios.get<PickerUser[]>('/api/users', {
        headers: headers(),
    });
    userPicker.value = data;
}

// Enrollable users = org users not already on the roster.
const enrollableUsers = computed(() => {
    const enrolled = new Set(detail.value?.enrollments.map((e) => e.user_id));

    return userPicker.value.filter((u) => !enrolled.has(u.id));
});

const userLabel = (u: PickerUser) =>
    [u.f_name, u.l_name].filter(Boolean).join(' ') || u.email || u.id;

async function run(fn: () => Promise<unknown>): Promise<void> {
    actionError.value = null;

    try {
        await fn();
    } catch (e) {
        actionError.value =
            (e as { response?: { data?: { message?: string } } }).response?.data
                ?.message ?? (e as Error).message;
    }
}

const attachTraining = () =>
    run(async () => {
        if (!attachTrainingId.value) {
            return;
        }

        await store.attachTraining(props.classId, {
            training_id: attachTrainingId.value,
            hours:
                attachHours.value.trim() === ''
                    ? null
                    : Number(attachHours.value),
        });
        attachTrainingId.value = '';
        attachHours.value = '';
    });

const enroll = () =>
    run(async () => {
        if (!enrollUserId.value) {
            return;
        }

        await store.enroll(props.classId, enrollUserId.value);
        enrollUserId.value = '';
    });
</script>

<template>
    <Head :title="detail?.name ?? 'Class'" />

    <div class="flex flex-col gap-6 p-4">
        <AsyncState :loading="loading" :error="error">
            <template v-if="detail">
                <div class="flex items-start justify-between gap-4">
                    <Heading
                        :title="detail.name"
                        :description="`${detail.scheduled_date ?? ''}${detail.location ? ' · ' + detail.location : ''}${detail.instructor ? ' · ' + detail.instructor : ''}`"
                    />
                    <div class="flex items-center gap-2">
                        <Badge
                            :variant="
                                detail.status === 'completed'
                                    ? 'secondary'
                                    : 'default'
                            "
                        >
                            {{ detail.status }}
                        </Badge>
                        <Button
                            v-if="
                                detail.can_edit && detail.status === 'scheduled'
                            "
                            variant="outline"
                            @click="editOpen = true"
                        >
                            Edit
                        </Button>
                    </div>
                </div>

                <p
                    v-if="actionError"
                    class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
                >
                    {{ actionError }}
                </p>

                <!-- Trainings -->
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold">Trainings</h2>
                    <div
                        v-if="detail.can_edit && detail.status === 'scheduled'"
                        class="flex flex-wrap items-end gap-2"
                    >
                        <div class="grid gap-1">
                            <Label class="text-xs">Add training</Label>
                            <Select v-model="attachTrainingId">
                                <SelectTrigger class="w-64">
                                    <SelectValue
                                        placeholder="Pick a training…"
                                    />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="t in trainings.library"
                                        :key="t.id"
                                        :value="t.id"
                                    >
                                        {{ t.name }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <div class="grid gap-1">
                            <Label class="text-xs">Hours</Label>
                            <Input
                                v-model="attachHours"
                                type="number"
                                step="0.25"
                                min="0"
                                class="w-24"
                            />
                        </div>
                        <Button type="button" @click="attachTraining"
                            >Add</Button
                        >
                    </div>

                    <div
                        v-if="detail.trainings.length === 0"
                        class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
                    >
                        No trainings attached yet.
                    </div>
                    <ul
                        v-else
                        class="divide-y divide-border rounded-md border border-border"
                    >
                        <li
                            v-for="t in detail.trainings"
                            :key="t.id"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <span>
                                {{ t.training_name }}
                                <span class="text-xs text-muted-foreground">
                                    {{
                                        t.std_freq_name ??
                                        (t.as_needed ? 'as-needed' : 'initial')
                                    }}{{ t.hours ? ` · ${t.hours}h` : '' }}
                                </span>
                            </span>
                            <button
                                v-if="
                                    detail.can_edit &&
                                    detail.status === 'scheduled'
                                "
                                type="button"
                                class="text-xs text-destructive hover:underline"
                                @click="
                                    run(() =>
                                        store.detachTraining(
                                            props.classId,
                                            t.id,
                                        ),
                                    )
                                "
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                </section>

                <!-- Roster -->
                <section class="space-y-2">
                    <h2 class="text-sm font-semibold">Roster</h2>
                    <div
                        v-if="detail.can_edit && detail.status === 'scheduled'"
                        class="flex flex-wrap items-end gap-2"
                    >
                        <div class="grid gap-1">
                            <Label class="text-xs">Enroll user</Label>
                            <Select v-model="enrollUserId">
                                <SelectTrigger class="w-64">
                                    <SelectValue placeholder="Pick a user…" />
                                </SelectTrigger>
                                <SelectContent>
                                    <SelectItem
                                        v-for="u in enrollableUsers"
                                        :key="u.id"
                                        :value="u.id"
                                    >
                                        {{ userLabel(u) }}
                                    </SelectItem>
                                </SelectContent>
                            </Select>
                        </div>
                        <Button type="button" @click="enroll">Enroll</Button>
                    </div>

                    <div
                        v-if="detail.enrollments.length === 0"
                        class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
                    >
                        Nobody enrolled yet.
                    </div>
                    <ul
                        v-else
                        class="divide-y divide-border rounded-md border border-border"
                    >
                        <li
                            v-for="e in detail.enrollments"
                            :key="e.id"
                            class="flex items-center justify-between gap-3 px-3 py-2 text-sm"
                        >
                            <span>
                                {{ e.user_name }}
                                <Badge
                                    variant="secondary"
                                    class="ml-1 text-[10px]"
                                >
                                    {{ e.status }}
                                </Badge>
                            </span>
                            <button
                                v-if="
                                    detail.can_edit &&
                                    detail.status === 'scheduled'
                                "
                                type="button"
                                class="text-xs text-destructive hover:underline"
                                @click="
                                    run(() =>
                                        store.unenroll(props.classId, e.id),
                                    )
                                "
                            >
                                Remove
                            </button>
                        </li>
                    </ul>
                </section>

                <ClassFormModal
                    v-model:open="editOpen"
                    mode="edit"
                    :target="detail"
                />
            </template>
        </AsyncState>
    </div>
</template>
