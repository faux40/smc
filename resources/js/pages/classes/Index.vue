<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import AsyncState from '@/components/AsyncState.vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ClassFormModal from '@/pages/classes/Partials/ClassFormModal.vue';
import { page as classesPage, showPage } from '@/routes/classes';
import { useClassesStore } from '@/stores/classes';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Classes', href: classesPage() }],
    },
});

const store = useClassesStore();
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
const canManage = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin ||
        authUser.value?.isManager,
    ),
);

const error = ref<string | null>(null);
const loading = ref(true);
const modalOpen = ref(false);

onMounted(async () => {
    if (authUser.value?.org_id) {
        store.subscribe(authUser.value.org_id);
    }

    try {
        await store.load();
    } catch (e) {
        error.value = (e as Error).message;
    } finally {
        loading.value = false;
    }
});
</script>

<template>
    <Head title="Classes" />

    <div class="flex flex-col gap-6 p-4">
        <div class="flex items-start justify-between gap-4">
            <Heading
                title="Classes"
                description="Schedule a class, attach trainings, and enroll users. Close it out to record completions (coming soon)."
            />
            <Button v-if="canManage" @click="modalOpen = true">
                + New class
            </Button>
        </div>

        <AsyncState
            :loading="loading"
            :error="error"
            :empty="store.library.length === 0"
            empty-text="No classes scheduled yet."
        >
            <div class="overflow-hidden rounded-md border border-border">
                <table class="min-w-full divide-y divide-border text-sm">
                    <thead class="bg-muted/40">
                        <tr>
                            <th class="px-4 py-2 text-left font-medium">
                                Name
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Date
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Trainings
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Enrolled
                            </th>
                            <th class="px-4 py-2 text-left font-medium">
                                Status
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-border">
                        <tr v-for="row in store.library" :key="row.id">
                            <td class="px-4 py-2">
                                <Link
                                    :href="showPage(row.id)"
                                    class="font-medium text-primary hover:underline"
                                >
                                    {{ row.name }}
                                </Link>
                                <div
                                    v-if="row.location"
                                    class="text-xs text-muted-foreground"
                                >
                                    {{ row.location }}
                                </div>
                            </td>
                            <td class="px-4 py-2">{{ row.scheduled_date }}</td>
                            <td class="px-4 py-2">{{ row.trainings_count }}</td>
                            <td class="px-4 py-2">
                                {{ row.enrollments_count }}
                            </td>
                            <td class="px-4 py-2">
                                <Badge
                                    :variant="
                                        row.status === 'completed'
                                            ? 'secondary'
                                            : 'default'
                                    "
                                >
                                    {{ row.status }}
                                </Badge>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </AsyncState>

        <ClassFormModal v-model:open="modalOpen" />
    </div>
</template>
