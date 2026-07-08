import axios from 'axios';
import { createPinia, setActivePinia } from 'pinia';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { toast } from 'vue-sonner';
import { useRemind } from '@/composables/useRemind';

vi.mock('axios');
vi.mock('@/echo', () => ({ realtimeTabId: () => 'test-tab' }));
vi.mock('vue-sonner', () => ({
    toast: { success: vi.fn(), info: vi.fn(), error: vi.fn() },
}));

describe('useRemind', () => {
    beforeEach(() => {
        setActivePinia(createPinia());
        vi.clearAllMocks();
    });

    it('remindOne toasts a plain success when no supervisor was CC’d', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { sent: true, status: 'due_soon', supervisor_notified: false },
        });

        await useRemind().remindOne('ta-1');

        expect(toast.success).toHaveBeenCalledWith('Reminder sent.');
    });

    it('remindOne notes the supervisor CC when one was notified', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: { sent: true, status: 'overdue', supervisor_notified: true },
        });

        await useRemind().remindOne('ta-1');

        expect(toast.success).toHaveBeenCalledWith(
            'Reminder sent (supervisor CC’d).',
        );
    });

    it('remindOne shows an info toast on a 422 (nothing to remind)', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockRejectedValue({
            response: { status: 422 },
        });

        await useRemind().remindOne('ta-1');

        expect(toast.info).toHaveBeenCalledWith(
            'Nothing to remind — this assignment is up to date.',
        );
        expect(toast.success).not.toHaveBeenCalled();
    });

    it('remindMany reports the tally with the supervisor-CC note', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {
                reminded_count: 8,
                skipped_count: 0,
                supervisors_notified_count: 2,
            },
        });

        const ok = await useRemind().remindMany(['a', 'b']);

        expect(ok).toBe(true);
        expect(toast.success).toHaveBeenCalledWith(
            'Reminder sent to 8 people (2 supervisors CC’d).',
        );
    });

    it('remindMany appends a skipped note and singularises counts', async () => {
        (axios.post as ReturnType<typeof vi.fn>).mockResolvedValue({
            data: {
                reminded_count: 1,
                skipped_count: 3,
                supervisors_notified_count: 1,
            },
        });

        await useRemind().remindMany(['a']);

        expect(toast.success).toHaveBeenCalledWith(
            'Reminder sent to 1 person (1 supervisor CC’d) · 3 skipped.',
        );
    });

    it('remindMany is a no-op on an empty selection', async () => {
        const ok = await useRemind().remindMany([]);

        expect(ok).toBe(false);
        expect(axios.post).not.toHaveBeenCalled();
    });
});
