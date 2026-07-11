/*
 * Location/department suggestions for variation pickers (Document data
 * page + generate bar): the org's people fields plus anything an
 * override row already uses (overrides can reference retired sites).
 * Caller is responsible for having loaded both stores.
 */

import { computed } from 'vue';
import { useMergeDataStore } from '@/stores/mergeData';
import { useUsersStore } from '@/stores/users';

export function useVariationSuggestions() {
    const usersStore = useUsersStore();
    const mergeData = useMergeDataStore();

    const location = computed(() => {
        const fromValues = mergeData.values
            .map((v) => v.location)
            .filter((l) => l !== '');

        return [...new Set([...usersStore.fieldOptions.location, ...fromValues])];
    });

    const department = computed(() => {
        const fromValues = mergeData.values
            .map((v) => v.department)
            .filter((d) => d !== '');

        return [...new Set([...usersStore.fieldOptions.department, ...fromValues])];
    });

    return { location, department };
}
