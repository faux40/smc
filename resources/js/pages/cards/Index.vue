<script setup lang="ts">
/*
 * Cards module home (custom-certs C2). Card stocks first — the geometry of
 * the purchased sheets everything else prints onto; the card-template
 * library joins it here in the next phase.
 */
import { Head, usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref } from 'vue';
import ErrorBanner from '@/components/ErrorBanner.vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import CardFontsList from '@/pages/cards/Partials/CardFontsList.vue';
import CardStockFormModal from '@/pages/cards/Partials/CardStockFormModal.vue';
import CardStocksList from '@/pages/cards/Partials/CardStocksList.vue';
import CardTemplatesList from '@/pages/cards/Partials/CardTemplatesList.vue';
import MergeKeysPanel from '@/pages/cards/Partials/MergeKeysPanel.vue';
import { page as cardsRoute } from '@/routes/cards';
import { useCardStocksStore } from '@/stores/cardStocks';
import type { CardStockRow } from '@/stores/cardStocks';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import { useErrorStore } from '@/stores/errors';

const PAGE_CTX = 'page:cards';

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Cards', href: cardsRoute() }],
    },
});

type Tab = 'templates' | 'stocks' | 'fonts' | 'keys';
const tab = ref<Tab>('templates');

const stocks = useCardStocksStore();
const templates = useCardTemplatesStore();
const errorStore = useErrorStore();
const page = usePage();

const authUser = computed(
    () =>
        page.props.auth.user as {
            isOwner?: boolean;
            isSuperAdmin?: boolean;
            isAdmin?: boolean;
        } | null,
);

/** Admin+ define stocks; Managers only pick one when printing. */
const canDefine = computed(() =>
    Boolean(
        authUser.value?.isOwner ||
        authUser.value?.isSuperAdmin ||
        authUser.value?.isAdmin,
    ),
);

const stockDialogOpen = ref(false);
const editingStock = ref<CardStockRow | null>(null);

function openNewStock(): void {
    editingStock.value = null;
    stockDialogOpen.value = true;
}

function openEditStock(stock: CardStockRow): void {
    editingStock.value = stock;
    stockDialogOpen.value = true;
}

onMounted(async () => {
    try {
        await Promise.all([templates.load(), stocks.load()]);
    } catch (e) {
        errorStore.reportFromAxios(e, PAGE_CTX, {
            fallback: 'Failed to load the cards module',
        });
    }
});
</script>

<template>
    <Head title="Cards" />

    <div class="space-y-5 p-4">
        <Heading
            title="Cards"
            description="Custom certificates and wallet cards printed onto purchased stock."
        />

        <ErrorBanner :context="PAGE_CTX" />

        <div class="flex gap-2 border-b border-border" role="tablist">
            <Button
                variant="ghost"
                role="tab"
                data-testid="tab-templates"
                :aria-selected="tab === 'templates'"
                :class="
                    tab === 'templates'
                        ? 'rounded-b-none border-b-2 border-primary'
                        : ''
                "
                @click="tab = 'templates'"
            >
                Card templates
            </Button>
            <Button
                variant="ghost"
                role="tab"
                data-testid="tab-stocks"
                :aria-selected="tab === 'stocks'"
                :class="
                    tab === 'stocks'
                        ? 'rounded-b-none border-b-2 border-primary'
                        : ''
                "
                @click="tab = 'stocks'"
            >
                Card stocks
            </Button>
            <Button
                variant="ghost"
                role="tab"
                data-testid="tab-fonts"
                :aria-selected="tab === 'fonts'"
                :class="
                    tab === 'fonts'
                        ? 'rounded-b-none border-b-2 border-primary'
                        : ''
                "
                @click="tab = 'fonts'"
            >
                Fonts
            </Button>
            <Button
                variant="ghost"
                role="tab"
                data-testid="tab-keys"
                :aria-selected="tab === 'keys'"
                :class="
                    tab === 'keys'
                        ? 'rounded-b-none border-b-2 border-primary'
                        : ''
                "
                @click="tab = 'keys'"
            >
                Merge keys
            </Button>
        </div>

        <CardTemplatesList v-if="tab === 'templates'" :can-define="canDefine" />

        <MergeKeysPanel v-else-if="tab === 'keys'" />

        <CardFontsList v-else-if="tab === 'fonts'" :can-define="canDefine" />

        <template v-else>
            <CardStocksList
                :can-define="canDefine"
                @new="openNewStock"
                @edit="openEditStock"
            />

            <CardStockFormModal
                v-model:open="stockDialogOpen"
                :editing="editingStock"
            />
        </template>
    </div>
</template>
