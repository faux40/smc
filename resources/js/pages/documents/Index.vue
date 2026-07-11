<script setup lang="ts">
/*
 * Documents module home (Phase D2): Generate tab (new-document bar +
 * the org's generated archive) and Templates tab (the DOCX/ODT master
 * library). Merge data entry lives on its own page (documents/data).
 */
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import GenerateBar from '@/pages/documents/Partials/GenerateBar.vue';
import GeneratedList from '@/pages/documents/Partials/GeneratedList.vue';
import TemplatesList from '@/pages/documents/Partials/TemplatesList.vue';
import { page as documentsRoute } from '@/routes/documents';
import { useDocTemplatesStore } from '@/stores/docTemplates';
import { useErrorStore } from '@/stores/errors';
import { useGeneratedDocumentsStore } from '@/stores/generatedDocuments';
import { useMergeDataStore } from '@/stores/mergeData';
import { useUsersStore } from '@/stores/users';

const PAGE_CTX = 'page:documents';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Documents', href: documentsRoute() }],
    },
});

type Tab = 'generate' | 'templates';
const tab = ref<Tab>('generate');

const templatesStore = useDocTemplatesStore();
const generatedStore = useGeneratedDocumentsStore();
const mergeData = useMergeDataStore();
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
        } | null,
);
const canDefine = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

onMounted(async () => {
    const orgId = authUser.value?.org_id;

    if (orgId) {
        templatesStore.subscribe(orgId);
        generatedStore.subscribe(orgId);
        mergeData.subscribe(orgId);
    }

    try {
        await Promise.all([
            templatesStore.load(),
            mergeData.load(),
            usersStore.loadFieldOptions(),
        ]);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to load the Documents module',
        });
    }
});
</script>

<template>
    <Head title="Documents" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Documents"
            description="Generate client-ready policies and letters from master templates merged with your organization's data."
        />

        <ErrorBanner :context="PAGE_CTX" />

        <div class="flex gap-2 border-b border-border" role="tablist">
            <Button
                variant="ghost"
                role="tab"
                data-testid="tab-generate"
                :aria-selected="tab === 'generate'"
                :class="tab === 'generate' ? 'border-b-2 border-primary rounded-b-none' : ''"
                @click="tab = 'generate'"
            >
                Generate
            </Button>
            <Button
                variant="ghost"
                role="tab"
                data-testid="tab-templates"
                :aria-selected="tab === 'templates'"
                :class="tab === 'templates' ? 'border-b-2 border-primary rounded-b-none' : ''"
                @click="tab = 'templates'"
            >
                Templates
            </Button>
        </div>

        <template v-if="tab === 'generate'">
            <GenerateBar />
            <GeneratedList />
        </template>
        <TemplatesList v-else :can-define="canDefine" />
    </div>
</template>
