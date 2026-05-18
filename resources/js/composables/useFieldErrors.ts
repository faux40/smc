/*
 * useFieldErrors — ergonomic per-field reads from the cross-cutting
 * error store, scoped to a single form's context string.
 *
 *   const fieldErrors = useFieldErrors('form:requirement');
 *
 *   <Input :class="fieldErrors.has('name') ? 'border-red-500' : ''" />
 *   <InputError :message="fieldErrors.message('name')" />
 *
 * Reactivity works without ref wrappers: `message`/`has` read directly
 * from the Pinia store, so the template's reactive render captures the
 * dependency on the store's state and re-renders when it changes.
 */

import { computed, type ComputedRef } from 'vue';
import { useErrorStore } from '@/stores/errors';

export interface UseFieldErrors {
    /** All field-error lists for this context, keyed by field name. */
    all:     ComputedRef<Record<string, string[]>>;
    /** First error message for a single field, or undefined. */
    message: (field: string) => string | undefined;
    /** True if a given field has any error attached. */
    has:     (field: string) => boolean;
    /** Wipe banner + field errors for this context (e.g. when a form opens). */
    clear:   () => void;
}

export function useFieldErrors(context: string): UseFieldErrors {
    const store = useErrorStore();

    return {
        all:     computed(() => store.getFieldErrors(context)),
        message: (field) => store.getFieldError(context, field),
        has:     (field) => store.getFieldError(context, field) !== undefined,
        clear:   () => store.clear(context),
    };
}
