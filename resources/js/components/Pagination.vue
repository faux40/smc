<script setup lang="ts">
/*
 * Reusable pagination control for server-paged tables. Stateless: it renders
 * the current page / range / total and emits page + per-page changes; the
 * owner (via useServerTable) holds the state and refetches.
 */
import { computed } from 'vue';
import { Button } from '@/components/ui/button';

const props = withDefaults(
    defineProps<{
        page: number;
        lastPage: number;
        total: number;
        perPage: number;
        perPageOptions?: number[];
        loading?: boolean;
    }>(),
    {
        perPageOptions: () => [25, 50, 100],
        loading: false,
    },
);

const emit = defineEmits<{
    (e: 'update:page', value: number): void;
    (e: 'update:perPage', value: number): void;
}>();

const rangeStart = computed(() =>
    props.total === 0 ? 0 : (props.page - 1) * props.perPage + 1,
);
const rangeEnd = computed(() =>
    Math.min(props.page * props.perPage, props.total),
);

function go(p: number): void {
    if (p >= 1 && p <= props.lastPage && p !== props.page) {
        emit('update:page', p);
    }
}
</script>

<template>
    <div class="flex flex-wrap items-center justify-between gap-3 text-sm">
        <div class="text-muted-foreground" data-testid="page-range">
            <template v-if="total === 0">No results</template>
            <template v-else>
                Showing {{ rangeStart }}–{{ rangeEnd }} of {{ total }}
            </template>
        </div>

        <div class="flex items-center gap-3">
            <label class="flex items-center gap-1 text-muted-foreground">
                <span>Per page</span>
                <select
                    class="rounded border border-border bg-background px-1.5 py-1"
                    :value="perPage"
                    aria-label="Rows per page"
                    @change="
                        emit(
                            'update:perPage',
                            Number(($event.target as HTMLSelectElement).value),
                        )
                    "
                >
                    <option v-for="n in perPageOptions" :key="n" :value="n">
                        {{ n }}
                    </option>
                </select>
            </label>

            <div class="flex items-center gap-1">
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="loading || page <= 1"
                    aria-label="Previous page"
                    @click="go(page - 1)"
                >
                    Prev
                </Button>
                <span
                    class="px-1 text-muted-foreground"
                    data-testid="page-indicator"
                >
                    Page {{ page }} of {{ Math.max(1, lastPage) }}
                </span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="loading || page >= lastPage"
                    aria-label="Next page"
                    @click="go(page + 1)"
                >
                    Next
                </Button>
            </div>
        </div>
    </div>
</template>
