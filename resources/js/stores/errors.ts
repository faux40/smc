/*
 * Cross-cutting error store. Every page/store that calls the backend
 * routes its 4xx/5xx responses through here so the user-facing
 * presentation (banner / inline field / toast) stays consistent.
 *
 * The store is partitioned by `context` — a stable string that names
 * the form or page that owns the error state. Two forms can be in
 * flight at once with separate error state and never collide.
 *
 * Engine response shape this store assumes:
 *
 *     { message: string, errors?: { fieldName: string[] } }
 *
 * --- HOW TO USE IN A COMPONENT --------------------------------------
 *
 * 1. In <script setup>:
 *
 *      import ErrorBanner from '@/components/ErrorBanner.vue';
 *      import { useErrorStore } from '@/stores/errors';
 *
 *      const errorStore = useErrorStore();
 *      const ERROR_CONTEXT = 'form:my-thing';   // unique, stable
 *
 * 2. In the action's catch block:
 *
 *      errorStore.reportFromAxios(e, ERROR_CONTEXT, {
 *          fallback: 'Failed to save',
 *          surface: 'banner',                   // optional; auto-routed
 *      });
 *
 * 3. Clear when the form opens / re-opens so a stale banner from a
 *    prior attempt doesn't stick around:
 *
 *      errorStore.clear(ERROR_CONTEXT);
 *
 * 4. In the template, mount the banner near the top of the form:
 *
 *      <ErrorBanner context="form:my-thing" />
 *
 * For inline per-field error text, use useFieldErrors(context) from
 * @/composables/useFieldErrors.
 */

import { defineStore } from 'pinia';
import { ref } from 'vue';
import { toast } from 'vue-sonner';

export type ErrorSurface = 'banner' | 'field' | 'toast';

export interface BannerError {
    context: string;
    message: string;
    fieldErrors?: Record<string, string[]>;
}

export interface ReportOpts {
    context: string;
    message: string;
    fieldErrors?: Record<string, string[]>;
    surface?: ErrorSurface;
}

export interface ReportFromAxiosOpts {
    /** Override the auto-routed surface (banner for 4xx, toast for 5xx / network). */
    surface?: ErrorSurface;
    /** Used when the response body has no `message` (or there is no response at all). */
    fallback?: string;
}

export const useErrorStore = defineStore('errors', () => {
    const bannerByContext      = ref<Record<string, BannerError>>({});
    const fieldErrorsByContext = ref<Record<string, Record<string, string[]>>>({});

    function report(opts: ReportOpts): void {
        const surface = opts.surface ?? 'banner';

        if (surface === 'toast') {
            toast.error(opts.message);
            return;
        }

        if (surface === 'banner') {
            bannerByContext.value = {
                ...bannerByContext.value,
                [opts.context]: {
                    context: opts.context,
                    message: opts.message,
                    fieldErrors: opts.fieldErrors,
                },
            };
        }

        // Field errors land in the per-context map regardless of surface
        // — useFieldErrors() reads from here so inline text appears under
        // each invalid input even when a banner is also visible.
        if (opts.fieldErrors) {
            fieldErrorsByContext.value = {
                ...fieldErrorsByContext.value,
                [opts.context]: opts.fieldErrors,
            };
        }
    }

    function clear(context?: string): void {
        if (context === undefined) {
            bannerByContext.value      = {};
            fieldErrorsByContext.value = {};
            return;
        }
        const { [context]: _b,  ...restB } = bannerByContext.value;
        const { [context]: _fe, ...restF } = fieldErrorsByContext.value;
        bannerByContext.value      = restB;
        fieldErrorsByContext.value = restF;
    }

    function getBanner(context: string): BannerError | null {
        return bannerByContext.value[context] ?? null;
    }

    function getFieldErrors(context: string): Record<string, string[]> {
        return fieldErrorsByContext.value[context] ?? {};
    }

    function getFieldError(context: string, field: string): string | undefined {
        const list = fieldErrorsByContext.value[context]?.[field];
        return list && list.length > 0 ? list[0] : undefined;
    }

    /**
     * Helper invoked from a caller's catch block. Parses an axios error
     * and routes it into the store with sensible defaults:
     *
     *   4xx with body   → banner (+ fieldErrors when present)
     *   5xx             → toast (transient infra; users should retry)
     *   no response     → toast with `opts.fallback ?? 'Network error'`
     *
     * Callers can override `surface` and supply a `fallback` message.
     */
    function reportFromAxios(error: any, context: string, opts: ReportFromAxiosOpts = {}): void {
        const status  = error?.response?.status as number | undefined;
        const body    = error?.response?.data    as { message?: string; errors?: Record<string, string[]> } | undefined;
        const message = body?.message ?? opts.fallback ?? error?.message ?? 'Something went wrong';

        const defaultSurface: ErrorSurface =
            status === undefined ? 'toast' :
            status >= 500        ? 'toast' :
            'banner';
        const surface = opts.surface ?? defaultSurface;

        report({
            context,
            message,
            fieldErrors: body?.errors,
            surface,
        });
    }

    return {
        bannerByContext, fieldErrorsByContext,
        report, clear, getBanner, getFieldErrors, getFieldError, reportFromAxios,
    };
});
