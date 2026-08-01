<script setup lang="ts">
/**
 * What became of this class's card print runs (custom-certs C4e).
 *
 * The sheets themselves are class documents — that's where they're viewed and
 * downloaded. This exists for the other half: a run takes a while, and when it
 * fails, nothing appears in Documents and there is nowhere else the reason
 * would ever surface. The requester has usually closed the dialog by then, so
 * the outcome has to live on the page.
 *
 * Renders nothing at all until a class has runs, so classes that never print
 * cards carry no extra furniture.
 */
import { usePage } from '@inertiajs/vue3';
import { computed, onMounted, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { useCardPrintRunsStore } from '@/stores/cardPrintRuns';
import type { CardPrintRunRow } from '@/stores/cardPrintRuns';

const props = defineProps<{ classId: string }>();

const store = useCardPrintRunsStore();
const page = usePage();

const runs = computed(() => store.runsFor(props.classId));

const STATUS_CLASS: Record<string, string> = {
    queued: 'bg-muted text-muted-foreground',
    processing: 'bg-blue-100 text-blue-900',
    done: 'bg-green-100 text-green-900',
    failed: 'bg-red-100 text-red-900',
};

/**
 * Status per run as of the last render. A run already settled when the page
 * loaded is not news; only a transition that happens while someone is looking
 * is worth a toast.
 */
const seen = ref<Record<string, string>>({});

watch(
    runs,
    (rows: CardPrintRunRow[]) => {
        const next: Record<string, string> = {};

        for (const row of rows) {
            const before = seen.value[row.id];
            next[row.id] = row.status;

            if (before === undefined || before === row.status) {
                continue;
            }

            if (row.status === 'done') {
                toast.success(
                    `Card sheets ready for ${row.topic_name ?? 'this topic'} — see Documents.`,
                );
            } else if (row.status === 'failed') {
                toast.error(row.error ?? 'The card print run failed.');
            }
        }

        seen.value = next;
    },
    { immediate: true },
);

/** The server's reason when it gave one, ours when it didn't. */
function reason(e: unknown, fallback: string): string {
    return (
        (e as { response?: { data?: { message?: string } } }).response?.data
            ?.message ?? fallback
    );
}

/**
 * Dismiss a settled run. Only the record goes — the sheets it filed live in
 * Documents with their own delete, so tidying the list never destroys output.
 */
async function clear(runId: string): Promise<void> {
    try {
        await store.destroy(props.classId, runId);
    } catch (e) {
        toast.error(reason(e, 'Could not clear that print run.'));
    }
}

onMounted(async () => {
    const orgId = (page.props.auth.user as { org_id?: string } | null)?.org_id;

    if (orgId) {
        store.subscribe(orgId);
    }

    // A failure from an earlier session still needs somewhere to be read —
    // including a failure to fetch it: a bare await here left a 403 or a
    // network blip as an unhandled rejection with nothing on screen.
    try {
        await store.load(props.classId);
    } catch (e) {
        toast.error(reason(e, 'Could not load the card print runs.'));
    }
});
</script>

<template>
    <div v-if="runs.length" class="space-y-2 border-t border-border pt-4">
        <h3 class="text-xs font-semibold text-muted-foreground">
            Card printing
        </h3>

        <ul class="space-y-2 text-sm">
            <li
                v-for="r in runs"
                :key="r.id"
                data-testid="card-run"
                class="rounded border border-border p-2"
            >
                <div class="flex flex-wrap items-baseline gap-2">
                    <span class="font-medium">{{
                        r.topic_name ?? 'Topic'
                    }}</span>
                    <span
                        class="rounded px-1.5 py-0.5 text-[10px] font-medium tracking-wide uppercase"
                        :class="STATUS_CLASS[r.status]"
                    >
                        {{ r.status }}
                    </span>
                    <span
                        v-if="r.card_count !== null"
                        class="text-xs text-muted-foreground"
                    >
                        {{ r.card_count }}
                        {{ r.card_count === 1 ? 'card' : 'cards' }}
                        <template v-if="r.sheet_count !== null">
                            · {{ r.sheet_count }}
                            {{ r.sheet_count === 1 ? 'sheet' : 'sheets' }}
                        </template>
                        <template v-if="r.include_backs">
                            · with backs</template
                        >
                        <!-- "1 card" alone reads like a run that went wrong;
                             this says it was the point. -->
                        <template v-if="r.proof"> · proof</template>
                    </span>
                    <span
                        v-if="r.created_at"
                        class="text-xs text-muted-foreground"
                    >
                        · {{ r.created_at }}
                    </span>

                    <!-- Settled runs only: clearing one mid-flight would
                         leave the job with nowhere to report its outcome. -->
                    <button
                        v-if="r.status === 'done' || r.status === 'failed'"
                        type="button"
                        data-testid="clear-run"
                        class="ml-auto text-xs text-muted-foreground hover:text-foreground hover:underline"
                        :aria-label="`Clear this print run for ${r.topic_name ?? 'this topic'}`"
                        title="Remove this entry. The sheets it filed stay in Documents."
                        @click="clear(r.id)"
                    >
                        Clear
                    </button>
                </div>

                <p v-if="r.error" class="mt-1 text-xs text-red-700">
                    {{ r.error }}
                </p>
            </li>
        </ul>
    </div>
</template>
