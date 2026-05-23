/*
 * Reverb smoke probe — the logic behind the header "Bug" button.
 *
 * Posts /realtime/ping (server dispatches a RealtimePing broadcast) and waits
 * for that event to round-trip back over the websocket. The point is feedback:
 *   - socket not connected            → immediate error toast;
 *   - round-trip arrives in time      → success is shown by the inbound
 *                                        monitor toast (useRealtime), timer cleared;
 *   - no round-trip within the budget → error toast naming the likely cause
 *                                        (queue worker not running / Reverb down);
 *   - the POST itself fails           → error toast.
 *
 * Pure + dependency-injected so the timing/branching is unit-testable; the
 * Vue glue lives in composables/useReverbProbe.ts.
 */

export interface ReverbProbeDeps {
    /** POST the ping (caller builds the message); resolves on 2xx, rejects otherwise. */
    post: () => Promise<unknown>;
    /** Is the websocket actually connected right now? */
    isConnected: () => boolean;
    /** Neutral/in-progress toast. */
    info: (message: string) => void;
    /** Success toast (green). */
    success: (message: string) => void;
    /** Error toast (red). */
    error: (message: string) => void;
    /** Round-trip budget before declaring failure (ms). */
    timeoutMs?: number;
}

export interface ReverbProbe {
    /** Fire a ping and arm the round-trip watchdog. */
    ping: () => Promise<void>;
    /** Call when a RealtimePing round-trip is observed. */
    onRoundTrip: () => void;
    /** Cancel any in-flight watchdog (e.g. on unmount). */
    dispose: () => void;
}

const DEFAULT_TIMEOUT_MS = 8000;

export function createReverbProbe(deps: ReverbProbeDeps): ReverbProbe {
    const timeoutMs = deps.timeoutMs ?? DEFAULT_TIMEOUT_MS;
    let timer: ReturnType<typeof setTimeout> | null = null;
    let pending = false;

    function stop(): void {
        if (timer !== null) {
            clearTimeout(timer);
            timer = null;
        }

        pending = false;
    }

    async function ping(): Promise<void> {
        if (!deps.isConnected()) {
            deps.error(
                'Realtime socket not connected — the browser can’t reach Reverb. Check VITE_REVERB_* and that the Reverb server is running.',
            );

            return;
        }

        pending = true;
        deps.info('Reverb ping sent — waiting for round-trip…');

        timer = setTimeout(() => {
            timer = null;
            pending = false;
            deps.error(
                `Dang! Bug says NO Reverb round-trip in ${Math.round(timeoutMs / 1000)}s — the broadcast wasn’t delivered. Is the queue worker running (php artisan queue:work)?`,
            );
        }, timeoutMs);

        try {
            await deps.post();
        } catch (e) {
            stop();
            deps.error(
                `Dang! Bug says Realtime ping failed to send — ${(e as Error)?.message ?? 'request error'}.`,
            );
        }
    }

    function onRoundTrip(): void {
        if (pending) {
            stop();
            deps.success('YES! Bug says Reverb round-trip OK — realtime is working.');
        }
    }

    return { ping, onRoundTrip, dispose: stop };
}
