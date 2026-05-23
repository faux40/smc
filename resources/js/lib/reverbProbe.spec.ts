import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { createReverbProbe } from '@/lib/reverbProbe';
import type { ReverbProbeDeps } from '@/lib/reverbProbe';

function deps(over: Partial<ReverbProbeDeps> = {}) {
    return {
        post: vi.fn().mockResolvedValue(undefined),
        isConnected: vi.fn().mockReturnValue(true),
        info: vi.fn(),
        success: vi.fn(),
        error: vi.fn(),
        timeoutMs: 5000,
        ...over,
    };
}

describe('createReverbProbe', () => {
    beforeEach(() => vi.useFakeTimers());
    afterEach(() => vi.useRealTimers());

    it('errors immediately and skips the POST when the socket is not connected', async () => {
        const d = deps({ isConnected: vi.fn().mockReturnValue(false) });
        const probe = createReverbProbe(d);

        await probe.ping();

        expect(d.error).toHaveBeenCalledWith(
            expect.stringContaining('not connected'),
        );
        expect(d.post).not.toHaveBeenCalled();
        expect(d.info).not.toHaveBeenCalled();
    });

    it('sends, then error-toasts when no round-trip arrives within the budget', async () => {
        const d = deps();
        const probe = createReverbProbe(d);

        await probe.ping();
        expect(d.info).toHaveBeenCalledWith(expect.stringContaining('sent'));
        expect(d.post).toHaveBeenCalledWith('header ping');
        expect(d.error).not.toHaveBeenCalled();

        vi.advanceTimersByTime(5000);

        expect(d.error).toHaveBeenCalledWith(
            expect.stringContaining('queue worker'),
        );
    });

    it('success-toasts (not error) when the round-trip arrives in time', async () => {
        const d = deps();
        const probe = createReverbProbe(d);

        await probe.ping();
        vi.advanceTimersByTime(2000);
        probe.onRoundTrip();
        vi.advanceTimersByTime(5000);

        expect(d.success).toHaveBeenCalledWith(expect.stringContaining('OK'));
        expect(d.error).not.toHaveBeenCalled();
    });

    it('error-toasts (and disarms the watchdog) when the POST fails', async () => {
        const d = deps({
            post: vi.fn().mockRejectedValue(new Error('Network Error')),
        });
        const probe = createReverbProbe(d);

        await probe.ping();
        expect(d.error).toHaveBeenCalledWith(
            expect.stringContaining('failed to send'),
        );

        vi.mocked(d.error).mockClear();
        vi.advanceTimersByTime(5000);
        // Watchdog was cancelled — no second (round-trip) error.
        expect(d.error).not.toHaveBeenCalled();
    });
});
