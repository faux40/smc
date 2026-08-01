<script setup lang="ts">
import { computed, reactive, ref, watch } from 'vue';
import CertEditor from '@/components/CertEditor.vue';
import CollapsibleSection from '@/components/CollapsibleSection.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { derivedExpiry } from '@/lib/expiry';
import { optionalNumber } from '@/lib/forms';
import { useClassesStore } from '@/stores/classes';
import type { ClassTrainingRow } from '@/stores/classes';

/**
 * Everything about one of a class's topics that a manager may need to set by
 * hand: its hours, when the credit expires, this class's answers for the
 * training's custom card fields, and the per-class certificate wording.
 *
 * One roll-up per topic, and one Save covering all of it. The endpoint merges
 * partial payloads, so this is a single PATCH — splitting it per concern would
 * mean a topic could end up half-saved when one of the calls failed.
 *
 * Definitions and wording are inherited from the training; this only supplies
 * this class's values. A completed class is frozen, so everything is shown and
 * nothing is editable — reopening is the way in.
 */
const props = defineProps<{
    classId: string;
    topic: ClassTrainingRow;
    /**
     * The date close-out would count the expiry from — the class's completion
     * date, else its scheduled date. Null while neither is known, in which
     * case there is nothing to derive and no hint to show.
     */
    derivedFrom: string | null;
    readOnly: boolean;
}>();

const store = useClassesStore();

const saving = ref(false);
const actionError = ref<string | null>(null);

const form = reactive({
    hours: '' as string | number,
    expire_date: '',
    cert_title: '',
    cert_text: '',
    cert_code: '',
});

/** This class's answers, keyed by card field id. '' clears one. */
const cardValues = reactive<Record<string, string>>({});

// Reseed whenever the topic changes underneath us — every save returns the
// whole class detail, and a peer's edit arrives by broadcast.
watch(
    () => props.topic,
    (topic) => {
        form.hours = topic.hours ?? '';
        form.expire_date = topic.expire_date ?? '';
        form.cert_title = topic.cert_title ?? '';
        form.cert_text = topic.cert_text ?? '';
        form.cert_code = topic.cert_code ?? '';

        for (const key of Object.keys(cardValues)) {
            delete cardValues[key];
        }

        for (const field of topic.card_fields) {
            // The training's default is shown as a placeholder, never seeded:
            // copying it here would freeze today's default onto this class.
            cardValues[field.id] = field.value ?? '';
        }

        actionError.value = null;
    },
    { immediate: true, deep: true },
);

/** What close-out would stamp if nobody sets an expiry by hand. */
const derived = computed(() =>
    props.derivedFrom === null
        ? null
        : derivedExpiry(
              props.derivedFrom,
              props.topic.repeating,
              props.topic.repeat_days,
          ),
);

const expiryHint = computed<string | null>(() => {
    if (props.derivedFrom === null) {
        return null;
    }

    if (derived.value === null) {
        return 'Leave blank — this training doesn’t repeat, so the credit never expires.';
    }

    const freq =
        props.topic.std_freq_name ?? `${props.topic.repeat_days} days`;

    return `Leave blank to use ${derived.value} (${freq}, from ${props.derivedFrom}).`;
});

const hoursLabel = computed(() => {
    const hours = optionalNumber(props.topic.hours);

    return hours === null ? null : `${hours}h`;
});

/** The header line, so a shut panel is still worth leaving shut. */
const summary = computed(() =>
    [
        hoursLabel.value,
        props.topic.expire_date
            ? `expires ${props.topic.expire_date}`
            : null,
        props.topic.card_fields.length
            ? `${props.topic.card_fields.length} card field${props.topic.card_fields.length === 1 ? '' : 's'}`
            : null,
    ]
        .filter(Boolean)
        .join(' · '),
);

/**
 * Open on arrival when something here was decided by hand — a card answer or
 * an expiry. Both are values a person entered and may need to check before
 * printing. Certificate wording is deliberately not a trigger: it's copied
 * from the training when the topic is attached, so nearly every topic has it
 * and it would mean every panel is always open.
 */
const defaultOpen = computed(
    () =>
        props.topic.expire_date !== null ||
        props.topic.card_fields.some((f) => f.value !== null),
);

function placeholderFor(defaultValue: string | null): string {
    return defaultValue === null || defaultValue === ''
        ? 'Leave blank to print nothing'
        : `Default: ${defaultValue}`;
}

const blank = (v: string) => (v.trim() === '' ? null : v);

async function save(): Promise<void> {
    saving.value = true;
    actionError.value = null;

    try {
        await store.updateTopic(props.classId, props.topic.id, {
            hours: optionalNumber(form.hours),
            // null, never '': blank means "derive it again" and would fail
            // the server's date rule as an empty string.
            expire_date: blank(form.expire_date),
            cert_title: blank(form.cert_title),
            cert_text: blank(form.cert_text),
            cert_code: blank(form.cert_code),
            card_values: { ...cardValues },
        });
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
    <CollapsibleSection
        :title="topic.training_name"
        :summary="summary"
        :default-open="defaultOpen"
    >
        <div data-testid="topic-body" class="space-y-5">
            <p
                v-if="actionError"
                class="rounded bg-red-50 p-2 text-sm text-red-800 dark:bg-red-900/30 dark:text-red-200"
            >
                {{ actionError }}
            </p>

            <p
                v-if="readOnly"
                class="rounded bg-amber-50 p-2 text-sm text-amber-900 dark:bg-amber-900/30 dark:text-amber-100"
            >
                This class is completed, so its records are read-only. Reopen it
                to change these values.
            </p>

            <!-- Hours + expiry: the two dates-and-numbers a close-out uses. -->
            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-1.5">
                    <Label :for="`hours_${topic.id}`">Hours</Label>
                    <Input
                        :id="`hours_${topic.id}`"
                        data-testid="topic-hours"
                        v-model="form.hours"
                        type="number"
                        min="0"
                        step="0.5"
                        :disabled="readOnly"
                    />
                </div>
                <div class="grid gap-1.5">
                    <Label :for="`expire_${topic.id}`">Expires</Label>
                    <Input
                        :id="`expire_${topic.id}`"
                        data-testid="topic-expire-date"
                        v-model="form.expire_date"
                        type="date"
                        :disabled="readOnly"
                    />
                    <p
                        v-if="expiryHint"
                        data-testid="expiry-hint"
                        class="text-xs text-muted-foreground"
                    >
                        {{ expiryHint }}
                    </p>
                </div>
            </div>

            <!-- Card fields: this class's answers for the training's own. -->
            <div class="space-y-3">
                <h3 class="text-xs font-semibold text-muted-foreground">
                    Card fields
                </h3>

                <p
                    v-if="!topic.card_fields.length"
                    class="text-sm text-muted-foreground"
                >
                    No card fields are defined for this training. Add them on
                    the training's page to collect values here.
                </p>

                <div
                    v-for="field in topic.card_fields"
                    :key="field.id"
                    class="grid gap-1"
                >
                    <div class="flex items-baseline justify-between gap-2">
                        <Label :for="`cv_${field.id}`">{{ field.label }}</Label>
                        <code class="text-xs text-muted-foreground">
                            {{ field.placeholder }}
                        </code>
                    </div>

                    <template v-if="field.type === 'rich'">
                        <textarea
                            :id="`cv_${field.id}`"
                            data-testid="card-value-rich"
                            v-model="cardValues[field.id]"
                            rows="4"
                            :maxlength="field.max_length"
                            :disabled="readOnly"
                            class="w-full rounded border border-input bg-background p-2 text-sm disabled:opacity-60"
                            :placeholder="placeholderFor(field.default_value)"
                        ></textarea>
                        <!-- This is where values are typed, so this is where
                             the supported markdown has to be discoverable. -->
                        <p
                            data-testid="card-value-format-hint"
                            class="text-xs text-muted-foreground"
                        >
                            **bold** and *italic* print formatted; a new line
                            starts a new line on the card.
                        </p>
                    </template>
                    <Input
                        v-else
                        :id="`cv_${field.id}`"
                        data-testid="card-value-short"
                        v-model="cardValues[field.id]"
                        :maxlength="field.max_length"
                        :disabled="readOnly"
                        :placeholder="placeholderFor(field.default_value)"
                    />
                </div>
            </div>

            <!-- Certificate: seeded from the training, overridden per class. -->
            <div class="space-y-3 border-t border-border pt-4">
                <h3 class="text-xs font-semibold text-muted-foreground">
                    SMC certificate
                </h3>

                <CertEditor
                    v-model:title="form.cert_title"
                    v-model:text="form.cert_text"
                    :id-prefix="`t_${topic.id}`"
                    :disabled="readOnly"
                />

                <div class="grid max-w-xs gap-1.5">
                    <Label :for="`cert_code_${topic.id}`">Cert code</Label>
                    <Input
                        :id="`cert_code_${topic.id}`"
                        data-testid="topic-cert-code"
                        v-model="form.cert_code"
                        :disabled="readOnly"
                        placeholder="e.g. FPAP"
                    />
                </div>
            </div>

            <div v-if="!readOnly" class="flex justify-end">
                <Button
                    type="button"
                    data-testid="topic-save"
                    :disabled="saving"
                    @click="save"
                >
                    {{ saving ? 'Saving…' : 'Save topic' }}
                </Button>
            </div>
        </div>
    </CollapsibleSection>
</template>
