import axios from 'axios';
import { onUnmounted } from 'vue';
import { toast } from 'vue-sonner';
import { useRealtime } from '@/composables/useRealtime';
import { createReverbProbe } from '@/lib/reverbProbe';

/**
 * Vue glue for the header "Bug" button: posts /realtime/ping and watches for
 * the RealtimePing broadcast to round-trip back over Reverb, toasting clear
 * feedback either way. See lib/reverbProbe.ts for the (tested) logic.
 */
// One reusable toast slot so the "sent → OK / failed" lifecycle replaces
// itself in place rather than stacking on each click.
const PROBE_TOAST = 'reverb-probe';

export function useReverbProbe() {
    const probe = createReverbProbe({
        post: (message) => axios.post('/realtime/ping', { message }),
        isConnected: () => {
            const connection = (
                window.Echo as
                    | {
                          connector?: {
                              pusher?: { connection?: { state?: string } };
                          };
                      }
                    | undefined
            )?.connector?.pusher?.connection;

            return connection?.state === 'connected';
        },
        info: (message) => toast.loading(message, { id: PROBE_TOAST }),
        success: (message) => toast.success(message, { id: PROBE_TOAST }),
        error: (message) => toast.error(message, { id: PROBE_TOAST }),
    });

    // The round-trip arrives on the public realtime-ping channel; useRealtime
    // also fires the inbound monitor toast, which is the success indication.
    const { bind } = useRealtime('realtime-ping', 'public');
    bind('RealtimePing', () => probe.onRoundTrip());

    onUnmounted(probe.dispose);

    return { ping: probe.ping };
}
