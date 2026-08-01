<script setup lang="ts">
/**
 * The org's uploaded font library (custom-certs C6c).
 *
 * A card design names its fonts; LibreOffice can only embed the ones it can
 * SEE, and substitutes the rest — the card then re-flows at different metrics,
 * which is what ruins a print onto purchased stock. Uploading the file here
 * is what clears a design's "not installed" warning.
 *
 * The family is read from inside the file, never from its name, so the list
 * shows what a design has to say to match it.
 */
import { computed, onMounted, ref } from 'vue';
import { toast } from 'vue-sonner';
import ErrorBanner from '@/components/ErrorBanner.vue';
import { Button } from '@/components/ui/button';
import { useCardFontsStore } from '@/stores/cardFonts';
import type { CardFontRow } from '@/stores/cardFonts';
import { useErrorStore } from '@/stores/errors';

const props = defineProps<{ canDefine: boolean }>();

const CTX = 'page:card-fonts';

const store = useCardFontsStore();
const errorStore = useErrorStore();

const input = ref<HTMLInputElement | null>(null);
const uploading = ref(false);

const fonts = computed(() => store.library);

function sizeLabel(bytes: number): string {
    return bytes < 1024 * 1024
        ? `${Math.round(bytes / 1024)} KB`
        : `${(bytes / 1024 / 1024).toFixed(1)} MB`;
}

async function choose(event: Event): Promise<void> {
    const file = (event.target as HTMLInputElement).files?.[0];

    if (!file) {
        return;
    }

    uploading.value = true;
    errorStore.clear(CTX);

    try {
        const row = await store.upload(file);
        toast.success(`“${row.family}” is now available to card designs.`);
    } catch (e) {
        errorStore.reportFromAxios(e, CTX, {
            fallback: 'Failed to upload that font.',
        });
    } finally {
        uploading.value = false;

        // Let the same file be picked again after a failure.
        if (input.value) {
            input.value.value = '';
        }
    }
}

async function remove(font: CardFontRow): Promise<void> {
    if (
        !confirm(
            `Remove “${font.family}”? Designs asking for it will print in a substituted font again.`,
        )
    ) {
        return;
    }

    try {
        await store.destroy(font.id);
    } catch (e) {
        errorStore.reportFromAxios(e, CTX, {
            fallback: 'Failed to remove that font.',
        });
    }
}

onMounted(async () => {
    try {
        await store.load();
    } catch (e) {
        errorStore.reportFromAxios(e, CTX, {
            fallback: 'Failed to load the font library.',
        });
    }
});
</script>

<template>
    <section class="space-y-3">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <p class="max-w-3xl text-xs text-muted-foreground">
                Fonts a card design asks for that aren't built in. Without the
                file, the converter substitutes a lookalike and the card
                re-flows at slightly different widths — which is what ruins a
                print onto purchased stock. The family is read from inside the
                file, so it has to match what the design asks for.
            </p>

            <div v-if="props.canDefine" class="shrink-0">
                <input
                    ref="input"
                    type="file"
                    accept=".ttf,.otf,font/ttf,font/otf"
                    data-testid="font-file"
                    class="hidden"
                    @change="choose"
                />
                <Button
                    type="button"
                    size="sm"
                    data-testid="upload-font"
                    :disabled="uploading"
                    @click="input?.click()"
                >
                    {{ uploading ? 'Uploading…' : 'Upload font' }}
                </Button>
            </div>
        </div>

        <ErrorBanner :context="CTX" />

        <p
            v-if="fonts.length === 0"
            class="rounded border border-dashed border-border p-3 text-xs text-muted-foreground"
        >
            No uploaded fonts. The built-in families (Liberation, DejaVu,
            Carlito, Caladea, and the Arial / Times / Calibri names that map
            onto them) always work without one.
        </p>

        <ul
            v-else
            class="divide-y divide-border rounded-md border border-border"
        >
            <li
                v-for="f in fonts"
                :key="f.id"
                data-testid="font-row"
                class="flex flex-wrap items-center justify-between gap-3 px-3 py-2 text-sm"
            >
                <div class="min-w-64">
                    <span class="font-medium">{{ f.family }}</span>
                    <span class="block text-xs text-muted-foreground">
                        {{ f.original_filename }} ·
                        {{ f.format.toUpperCase() }} ·
                        {{ sizeLabel(f.size) }}
                    </span>
                </div>

                <Button
                    v-if="f.can_delete"
                    :data-testid="`delete-font-${f.id}`"
                    variant="outline"
                    size="sm"
                    @click="remove(f)"
                >
                    Remove
                </Button>
            </li>
        </ul>
    </section>
</template>
