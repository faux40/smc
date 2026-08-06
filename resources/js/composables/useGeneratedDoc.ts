import { ref } from 'vue';
import type { Ref } from 'vue';
import type { GeneratedDoc } from '@/components/AttachmentViewer.vue';

/**
 * Open a class's generated PDFs in the in-app viewer (preview + browser
 * download/print, plus "save to this class's files") rather than a second tab.
 *
 * Lifted out of classes/Show.vue when the classes index gained a per-row print
 * icon. The index opens documents for whichever row was clicked, so `classId`
 * is an argument here rather than page state — the one real difference between
 * the two callers.
 */

/** UI kind → the route segment the server actually exposes. */
const DOC_PATHS: Record<GeneratedDoc['kind'], string> = {
    certificates: 'certificates',
    summary: 'summary',
    'sign-in': 'sign-in-sheet',
    'name-check': 'name-check',
};

export interface UseGeneratedDoc {
    open: Ref<boolean>;
    active: Ref<GeneratedDoc | null>;
    openDoc: (
        classId: string,
        kind: GeneratedDoc['kind'],
        title: string,
        columns?: string[],
    ) => void;
}

export function useGeneratedDoc(): UseGeneratedDoc {
    const open = ref(false);
    const active = ref<GeneratedDoc | null>(null);

    function openDoc(
        classId: string,
        kind: GeneratedDoc['kind'],
        title: string,
        columns?: string[],
    ): void {
        // Columns ride on the src AND on the doc, so the preview and the "save
        // to this class's files" action render the same sheet.
        const query = columns?.length
            ? `?${columns
                  .map((c) => `columns%5B%5D=${encodeURIComponent(c)}`)
                  .join('&')}`
            : '';

        active.value = {
            kind,
            title,
            classId,
            src: `/api/classes/${classId}/${DOC_PATHS[kind]}${query}`,
            columns,
        };
        open.value = true;
    }

    return { open, active, openDoc };
}
