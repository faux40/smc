<script setup lang="ts">
import { AlertCircle } from 'lucide-vue-next';
import { computed } from 'vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { useErrorStore } from '@/stores/errors';

/*
 * Standardized error banner driven by the cross-cutting error store.
 * Mount once per form/page; pass the same `context` string that catch
 * blocks use when calling reportFromAxios().
 *
 *   <ErrorBanner context="form:requirement" />
 *
 * Field-level errors live in the same store and are surfaced inline
 * via useFieldErrors(), so this banner intentionally renders only the
 * top-level message (server `message`, fallback, or network error)
 * to avoid duplicating field text under each input.
 */

const props = withDefaults(
    defineProps<{
        context: string;
        title?: string;
    }>(),
    {
        title: 'Something went wrong',
    },
);

const errorStore = useErrorStore();
const banner = computed(() => errorStore.getBanner(props.context));
</script>

<template>
    <Alert v-if="banner" variant="destructive">
        <AlertCircle class="size-4" />
        <AlertTitle>{{ title }}</AlertTitle>
        <AlertDescription>{{ banner.message }}</AlertDescription>
    </Alert>
</template>
