<script setup lang="ts">
import { computed, onMounted, watch } from 'vue';
import CertEditor from '@/components/CertEditor.vue';
import InputError from '@/components/InputError.vue';
import MultiSelectDropdown from '@/components/MultiSelectDropdown.vue';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { useFieldErrors } from '@/composables/useFieldErrors';
import type { TrainingFormState } from '@/lib/trainingForm';
import { useCardStocksStore } from '@/stores/cardStocks';
import { useCardTemplatesStore } from '@/stores/cardTemplates';
import { useStdFrequenciesStore } from '@/stores/stdFrequencies';
import { useTrainingsStore } from '@/stores/trainings';

/**
 * The shared editable fields for a training, used by both the create modal
 * (TrainingFormModal) and the inline edit form on the detail page
 * (trainings/Show). Two-way bound via `v-model`; field errors render from the
 * error store under `context`.
 */
const form = defineModel<TrainingFormState>({ required: true });
const props = defineProps<{
    context: string;
    /**
     * The training being edited, when editing. The Satisfied-by picker uses
     * it to exclude the training itself and anything whose chain runs through
     * it — options that would loop the ladder. Absent on create, where no
     * loop is possible yet.
     */
    selfId?: string | null;
}>();

const frequencies = useStdFrequenciesStore();
const cardTemplates = useCardTemplatesStore();
const cardStocks = useCardStocksStore();
const trainings = useTrainingsStore();
const fieldErrors = useFieldErrors(props.context);

/**
 * Library options for the Satisfied-by picker, minus self and minus every
 * training that (transitively) chains up THROUGH self — the server refuses
 * those as cycles, so offering them would only manufacture a validation
 * error. The walk follows every branch of the DAG (a training may name
 * several satisfiers); diamonds converge harmlessly under the visited-set,
 * like the server walk it mirrors.
 */
const satisfierOptions = computed(() => {
    if (!props.selfId) {
        return trainings.library;
    }

    const parentsOf = new Map(
        trainings.library.map((t) => [t.id, t.satisfied_by_ids]),
    );

    const chainsThroughSelf = (id: string): boolean => {
        const seen = new Set<string>([id]);
        let frontier = [id];

        while (frontier.length > 0) {
            if (frontier.includes(props.selfId!)) {
                return true;
            }

            const next: string[] = [];

            for (const current of frontier) {
                for (const parent of parentsOf.get(current) ?? []) {
                    if (!seen.has(parent)) {
                        seen.add(parent);
                        next.push(parent);
                    }
                }
            }

            frontier = next;
        }

        return false;
    };

    return trainings.library.filter((t) => !chainsThroughSelf(t.id));
});

onMounted(async () => {
    if (!frequencies.loaded) {
        try {
            await frequencies.load();
        } catch {
            // Non-fatal — the picker will be empty.
        }
    }

    if (!cardTemplates.loaded) {
        try {
            await cardTemplates.load();
        } catch {
            // Non-fatal — the picker will be empty.
        }
    }

    if (!cardStocks.loaded) {
        try {
            await cardStocks.load();
        } catch {
            // Non-fatal — the picker will be empty.
        }
    }

    if (!trainings.loaded) {
        try {
            await trainings.load();
        } catch {
            // Non-fatal — the Satisfied-by picker will be empty.
        }
    }
});

/** '' is the select's "no card" option; the API wants a real null. */
function chooseCardTemplate(value: string): void {
    form.value.card_template_id = value === '' ? null : value;
}

function chooseCardStock(value: string): void {
    form.value.card_stock_id = value === '' ? null : value;
}

// Unchecking "repeating" drops the frequency.
watch(
    () => form.value.repeating,
    (next) => {
        if (!next) {
            form.value.std_freq_id = null;
        }
    },
);
</script>

<template>
    <div class="space-y-4">
        <div class="grid gap-2">
            <Label for="t_name">Name</Label>
            <Input id="t_name" v-model="form.name" required autofocus />
            <InputError :message="fieldErrors.message('name')" />
        </div>

        <div class="grid gap-2">
            <Label for="t_nickname">Nickname (optional)</Label>
            <Input
                id="t_nickname"
                v-model="form.nickname"
                placeholder="Short alias, e.g. FallPro"
            />
            <InputError :message="fieldErrors.message('nickname')" />
        </div>

        <div class="grid gap-2">
            <Label for="t_desc">Description</Label>
            <textarea
                id="t_desc"
                v-model="form.description"
                rows="3"
                class="w-full rounded border border-input bg-background p-2 text-sm"
            ></textarea>
            <InputError :message="fieldErrors.message('description')" />
        </div>

        <div class="grid gap-2">
            <Label for="t_hours">Default hours</Label>
            <Input
                id="t_hours"
                type="number"
                step="0.25"
                min="0"
                v-model="form.default_hours"
                class="w-32"
                placeholder="e.g. 4"
            />
            <p class="text-xs text-muted-foreground">
                Pre-fills the hours when this topic is added to a class.
            </p>
            <InputError :message="fieldErrors.message('default_hours')" />
        </div>

        <div class="space-y-2">
            <p class="text-sm font-medium">Timing</p>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox v-model="form.initial_only" />
                Initial-only (one-time on assignment)
            </label>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox v-model="form.repeating" />
                Repeating
            </label>
            <label class="flex items-center gap-2 text-sm">
                <Checkbox v-model="form.as_needed" />
                As-needed (no schedule; just available to record)
            </label>
            <InputError :message="fieldErrors.message('initial_only')" />
            <InputError :message="fieldErrors.message('repeating')" />
            <InputError :message="fieldErrors.message('as_needed')" />
            <p class="text-xs text-muted-foreground">
                At least one must be set. Initial-only and repeating are
                mutually exclusive.
            </p>
        </div>

        <div v-if="form.repeating" class="grid gap-2">
            <Label for="t_freq">Frequency</Label>
            <Select v-model="form.std_freq_id">
                <SelectTrigger id="t_freq">
                    <SelectValue placeholder="Pick a frequency…" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="f in frequencies.library"
                        :key="f.id"
                        :value="f.id"
                    >
                        {{ f.name }} ({{ f.repeat_days }} day{{
                            f.repeat_days === 1 ? '' : 's'
                        }})
                    </SelectItem>
                </SelectContent>
            </Select>
            <InputError :message="fieldErrors.message('std_freq_id')" />
        </div>

        <div class="grid gap-2 border-t border-border pt-3">
            <Label for="t_satisfied_by">
                Satisfied by (higher trainings)
            </Label>
            <MultiSelectDropdown
                id="t_satisfied_by"
                v-model="form.satisfied_by_ids"
                :options="
                    satisfierOptions.map((t) => ({
                        id: t.id,
                        label: t.name,
                    }))
                "
                placeholder="None — pick the higher trainings that count"
                search-placeholder="Search trainings…"
            />
            <p class="text-xs text-muted-foreground">
                A person holding a current credential for ANY checked training
                also counts as satisfying this one (their certificate stays the
                higher one). Chains upward: if a checked training names its own
                higher levels, those count here too.
            </p>
            <InputError :message="fieldErrors.message('satisfied_by_ids')" />
        </div>

        <div class="space-y-3 border-t border-border pt-3">
            <p class="text-sm font-medium">SMC Certificate</p>
            <p class="text-xs text-muted-foreground">
                The built-in certificate — defaults copied onto a class when
                this topic is added, then printed on the certificate.
            </p>

            <CertEditor
                v-model:title="form.cert_title"
                v-model:text="form.cert_text"
                :context="context"
            />

            <div class="grid gap-2">
                <Label for="t_cert_code">Cert code</Label>
                <Input
                    id="t_cert_code"
                    v-model="form.cert_code"
                    placeholder="e.g. FPAP"
                />
                <p class="text-xs text-muted-foreground">
                    Prefix for the certificate numbers minted at class
                    close-out &mdash; e.g. FPAP becomes FPAP20260806-001.
                    Blank uses the generic CERT prefix.
                </p>
                <InputError :message="fieldErrors.message('cert_code')" />
            </div>
        </div>

        <div class="space-y-3 border-t border-border pt-3">
            <p class="text-sm font-medium">Card</p>

            <div class="grid gap-2">
                <Label for="t_card_template">Custom card</Label>
                <select
                    id="t_card_template"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                    :value="form.card_template_id ?? ''"
                    @change="
                        chooseCardTemplate(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">No card</option>
                    <option
                        v-for="t in cardTemplates.library"
                        :key="t.id"
                        :value="t.id"
                    >
                        {{ t.name }}
                    </option>
                </select>
                <p class="text-xs text-muted-foreground">
                    A printed card or custom certificate for this training —
                    separate from the SMC certificate above. Designs live on the
                    Cards page.
                </p>
                <InputError
                    :message="fieldErrors.message('card_template_id')"
                />
            </div>

            <div class="grid gap-2">
                <Label for="t_card_stock">Card stock</Label>
                <select
                    id="t_card_stock"
                    data-testid="training-card-stock"
                    class="h-9 rounded-md border border-input bg-background px-2 text-sm"
                    :value="form.card_stock_id ?? ''"
                    @change="
                        chooseCardStock(
                            ($event.target as HTMLSelectElement).value,
                        )
                    "
                >
                    <option value="">Ask when printing</option>
                    <option
                        v-for="s in cardStocks.library"
                        :key="s.id"
                        :value="s.id"
                    >
                        {{ s.name }}
                    </option>
                </select>
                <p class="text-xs text-muted-foreground">
                    The purchased sheet these cards print onto. Pre-selected
                    when printing a class, and still changeable there.
                </p>
                <InputError :message="fieldErrors.message('card_stock_id')" />
            </div>
        </div>

        <div class="space-y-3 border-t border-border pt-3">
            <p class="text-sm font-medium">Class defaults</p>

            <div class="grid gap-2">
                <Label for="t_def_trainer">Default trainer</Label>
                <Input id="t_def_trainer" v-model="form.default_trainer" />
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div class="grid gap-2">
                    <Label for="t_def_loc">Default location</Label>
                    <Input id="t_def_loc" v-model="form.default_location" />
                </div>
                <div class="grid gap-2">
                    <Label for="t_def_addr">Default address</Label>
                    <textarea
                        id="t_def_addr"
                        v-model="form.default_address"
                        rows="2"
                        class="rounded border border-input bg-background p-2 text-sm"
                    ></textarea>
                </div>
            </div>
        </div>
    </div>
</template>
