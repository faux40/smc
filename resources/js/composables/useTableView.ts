import { computed } from 'vue';
import { usePreferencesStore } from '@/stores/preferences';

/*
 * Per-view column control on top of usePreferencesStore.
 *
 *   const view = useTableView('users', [
 *     { key: 'name', label: 'Name', sortable: true },
 *     { key: 'email', label: 'Email' },
 *     { key: 'dept', label: 'Department', defaultVisible: false },
 *   ]);
 *
 * Merges the page's declared default columns with the user's saved
 * `visible_columns` / `column_order` overrides and exposes the resolved,
 * ordered set + toggle/move that persist back through the prefs store. The
 * page renders columns from `view.visibleColumns`. New columns added in code
 * but absent from a user's saved order are appended (never dropped).
 */
export interface ColumnDef {
    key: string;
    label: string;
    /** Default visibility when the user hasn't overridden it (default true). */
    defaultVisible?: boolean;
    sortable?: boolean;
}

export interface ResolvedColumn {
    key: string;
    label: string;
    visible: boolean;
    sortable: boolean;
}

export type MoveDir = 'left' | 'right';

export function useTableView(viewId: string, columns: ColumnDef[]) {
    const prefs = usePreferencesStore();
    const byKey = new Map(columns.map((c) => [c.key, c]));

    const orderedKeys = computed<string[]>(() => {
        const saved = prefs.view(viewId).column_order ?? [];
        const known = saved.filter((k) => byKey.has(k));
        const rest = columns.map((c) => c.key).filter((k) => !known.includes(k));

        return [...known, ...rest];
    });

    const columnsResolved = computed<ResolvedColumn[]>(() => {
        const vis = prefs.view(viewId).visible_columns ?? {};

        return orderedKeys.value.map((key) => {
            const def = byKey.get(key)!;

            return {
                key,
                label: def.label,
                sortable: def.sortable ?? false,
                visible: vis[key] ?? def.defaultVisible ?? true,
            };
        });
    });

    const visibleColumns = computed(() =>
        columnsResolved.value.filter((c) => c.visible),
    );

    function isVisible(key: string): boolean {
        return columnsResolved.value.find((c) => c.key === key)?.visible ?? true;
    }

    function toggle(key: string): void {
        const current = prefs.view(viewId).visible_columns ?? {};
        prefs.update(viewId, {
            visible_columns: { ...current, [key]: !isVisible(key) },
        });
    }

    function move(key: string, dir: MoveDir): void {
        const keys = orderedKeys.value.slice();
        const i = keys.indexOf(key);
        const j = dir === 'left' ? i - 1 : i + 1;

        if (i === -1 || j < 0 || j >= keys.length) {
            return;
        }

        [keys[i], keys[j]] = [keys[j], keys[i]];
        prefs.update(viewId, { column_order: keys });
    }

    return {
        columns: columnsResolved,
        visibleColumns,
        isVisible,
        toggle,
        move,
    };
}
