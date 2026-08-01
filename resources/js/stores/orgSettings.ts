/*
 * Org-level configuration surfaced from the `org` Inertia shared prop.
 *
 * training_thresholds is a sparse JSON object — missing keys fall back to
 * built-in defaults. Components and widgets read from here instead of
 * hard-coding numbers so admins can tune them on the settings page.
 */
import { defineStore } from 'pinia';
import { computed } from 'vue';
import { usePage } from '@inertiajs/vue3';

export const DUE_SOON_DAYS_DEFAULT = 60;
export const EXPIRING_SOON_DAYS_DEFAULT = 30;

interface TrainingThresholds {
    due_soon_days?: number;
    expiring_soon_days?: number;
}

export const useOrgSettingsStore = defineStore('orgSettings', () => {
    const page = usePage();

    const thresholds = computed<TrainingThresholds | null>(() => {
        const org = page.props.org as
            | { training_thresholds: TrainingThresholds | null }
            | null
            | undefined;
        return org?.training_thresholds ?? null;
    });

    const dueSoonDays = computed(
        () => thresholds.value?.due_soon_days ?? DUE_SOON_DAYS_DEFAULT,
    );
    const expiringSoonDays = computed(
        () =>
            thresholds.value?.expiring_soon_days ?? EXPIRING_SOON_DAYS_DEFAULT,
    );

    return { thresholds, dueSoonDays, expiringSoonDays };
});
