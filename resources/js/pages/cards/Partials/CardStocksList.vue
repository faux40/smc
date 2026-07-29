<script setup lang="ts">
/*
 * Card-stock library: system stocks (read-only, console-managed) plus the
 * org's own. Managers see the list because they pick a stock when printing;
 * only Admins get the New / Edit / Delete controls.
 *
 * Sizes are shown in inches — points are the storage unit, not a readable
 * one, and inches are what the packaging quotes.
 */
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { fromPoints } from '@/lib/cardGeometry';
import { useCardStocksStore } from '@/stores/cardStocks';
import type { CardStockRow } from '@/stores/cardStocks';

defineProps<{ canDefine: boolean }>();
const emit = defineEmits<{
    (e: 'edit', stock: CardStockRow): void;
    (e: 'new'): void;
}>();

const store = useCardStocksStore();

const inches = (points: number): string => String(fromPoints(points, 'in'));

const cardSize = (s: CardStockRow): string =>
    `${inches(s.card_width)} × ${inches(s.card_height)} in`;

const pageSize = (s: CardStockRow): string =>
    `${inches(s.page_width)} × ${inches(s.page_height)} in page`;

async function remove(stock: CardStockRow): Promise<void> {
    if (
        !window.confirm(
            `Delete the card stock "${stock.name}"? Templates that used it keep their own layout.`,
        )
    ) {
        return;
    }

    await store.destroy(stock.id);
}
</script>

<template>
    <section class="space-y-3">
        <div class="flex items-center justify-between gap-3">
            <div>
                <h2 class="text-sm font-semibold">Card stocks</h2>
                <p class="text-xs text-muted-foreground">
                    The measurements of the purchased sheets you print onto.
                </p>
            </div>
            <Button
                v-if="canDefine"
                data-testid="new-stock"
                variant="outline"
                size="sm"
                @click="emit('new')"
            >
                New stock
            </Button>
        </div>

        <p
            v-if="store.library.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No card stocks yet — add the sheet you print onto to start.
        </p>

        <ul
            v-else
            class="divide-y divide-border rounded-md border border-border"
        >
            <li
                v-for="s in store.library"
                :key="s.id"
                class="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-sm"
            >
                <div class="min-w-64">
                    <span class="flex items-center gap-2 font-medium">
                        {{ s.name }}
                        <Badge
                            v-if="s.is_system"
                            variant="secondary"
                            class="text-[10px]"
                        >
                            System
                        </Badge>
                    </span>
                    <span class="text-xs text-muted-foreground">
                        {{ cardSize(s) }} · {{ s.column_count }} ×
                        {{ s.row_count }} · {{ s.per_sheet }} per sheet ·
                        {{ pageSize(s) }}
                    </span>
                </div>

                <div class="flex gap-2">
                    <Button
                        v-if="s.can_edit"
                        :data-testid="`edit-${s.id}`"
                        variant="outline"
                        size="sm"
                        @click="emit('edit', s)"
                    >
                        Edit
                    </Button>
                    <Button
                        v-if="s.can_delete"
                        :data-testid="`delete-${s.id}`"
                        variant="outline"
                        size="sm"
                        @click="remove(s)"
                    >
                        Delete
                    </Button>
                </div>
            </li>
        </ul>
    </section>
</template>
