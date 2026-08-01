import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import {
    DUE_SOON_DAYS_DEFAULT,
    EXPIRING_SOON_DAYS_DEFAULT,
    useOrgSettingsStore,
} from '@/stores/orgSettings';

const { mockProps } = vi.hoisted(() => ({
    mockProps: { value: {} as Record<string, unknown> },
}));

vi.mock('@inertiajs/vue3', () => ({
    usePage: () => ({ props: mockProps.value }),
}));

describe('useOrgSettingsStore', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
        mockProps.value = {};
    });

    it('returns defaults when org prop is absent', () => {
        const store = useOrgSettingsStore();
        expect(store.dueSoonDays).toBe(DUE_SOON_DAYS_DEFAULT);
        expect(store.expiringSoonDays).toBe(EXPIRING_SOON_DAYS_DEFAULT);
    });

    it('returns defaults when training_thresholds is null', () => {
        mockProps.value = { org: { training_thresholds: null } };
        const store = useOrgSettingsStore();
        expect(store.dueSoonDays).toBe(DUE_SOON_DAYS_DEFAULT);
        expect(store.expiringSoonDays).toBe(EXPIRING_SOON_DAYS_DEFAULT);
    });

    it('uses due_soon_days from training_thresholds', () => {
        mockProps.value = {
            org: { training_thresholds: { due_soon_days: 45 } },
        };
        const store = useOrgSettingsStore();
        expect(store.dueSoonDays).toBe(45);
        expect(store.expiringSoonDays).toBe(EXPIRING_SOON_DAYS_DEFAULT);
    });

    it('uses expiring_soon_days from training_thresholds', () => {
        mockProps.value = {
            org: { training_thresholds: { expiring_soon_days: 14 } },
        };
        const store = useOrgSettingsStore();
        expect(store.dueSoonDays).toBe(DUE_SOON_DAYS_DEFAULT);
        expect(store.expiringSoonDays).toBe(14);
    });

    it('uses both custom values when both are set', () => {
        mockProps.value = {
            org: {
                training_thresholds: {
                    due_soon_days: 45,
                    expiring_soon_days: 7,
                },
            },
        };
        const store = useOrgSettingsStore();
        expect(store.dueSoonDays).toBe(45);
        expect(store.expiringSoonDays).toBe(7);
    });
});
