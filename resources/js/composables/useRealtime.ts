import { onUnmounted } from 'vue';
import { realtimeTabId } from '@/echo';
import { debugRealtimeEvent } from '@/lib/debugRealtime';

/*
 * Subscribe to a private/public Reverb channel for the lifetime of a Vue
 * component. Wraps the handler so payloads whose `origin_tab` matches
 * the current tab's UUID are skipped — those are echoes of the local
 * action and the UI has already optimistically updated.
 *
 * Usage:
 *   const { bind } = useRealtime(`org.${orgId}`);
 *   bind('OrganizationUpdated', (payload) => store.applyPatch(payload));
 *
 * Pass `public: true` (or pass a name starting with `public:`) to use a
 * public channel instead. For org-scoped channels the default `private`
 * mode is correct.
 */

type ChannelMode = 'private' | 'public';

interface RealtimeHandle {
    bind: (eventName: string, handler: (payload: any) => void) => void;
    leave: () => void;
}

export function useRealtime(channelName: string, mode: ChannelMode = 'private'): RealtimeHandle {
    const echo = window.Echo;
    if (!echo) {
        // Echo isn't initialized (e.g., SSR or test) — return a no-op handle so
        // callers don't have to null-check.
        return { bind: () => undefined, leave: () => undefined };
    }

    const channel = mode === 'public' ? echo.channel(channelName) : echo.private(channelName);
    const ownTab = realtimeTabId();
    const boundEvents: string[] = [];

    const bind = (eventName: string, handler: (payload: any) => void): void => {
        boundEvents.push(eventName);
        channel.listen(eventName, (payload: any) => {
            debugRealtimeEvent(channelName, eventName, payload);
            if (payload?.origin_tab && payload.origin_tab === ownTab) {
                return; // self-echo — skip
            }
            handler(payload);
        });
    };

    const leave = (): void => {
        boundEvents.forEach((evt) => channel.stopListening(evt));
        if (mode === 'public') {
            echo.leaveChannel(channelName);
        } else {
            echo.leaveChannel(`private-${channelName}`);
        }
    };

    onUnmounted(leave);

    return { bind, leave };
}
