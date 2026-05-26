import type { RqmtElementRow } from '@/stores/rqmtElements';

/**
 * Human label for a single rqmt-element's timing flags, e.g.
 * "repeating · as-needed". Shared by the requirement detail page and the
 * assignment editor's read-only schedule preview. Returns "—" when no flag
 * is set.
 */
export function elementTimingLabel(
    row: Pick<RqmtElementRow, 'initial_only' | 'repeating' | 'as_needed'>,
): string {
    const parts: string[] = [];

    if (row.initial_only) {
        parts.push('initial-only');
    }

    if (row.repeating) {
        parts.push('repeating');
    }

    if (row.as_needed) {
        parts.push('as-needed');
    }

    return parts.length > 0 ? parts.join(' · ') : '—';
}
