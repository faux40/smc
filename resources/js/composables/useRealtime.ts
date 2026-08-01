import { getCurrentInstance, onUnmounted } from 'vue';
import { realtimeTabId } from '@/echo';

/*
 * Subscribe to a private/public Reverb channel. Wraps the handler so payloads
 * whose `origin_tab` matches the current tab's UUID are skipped — those are
 * echoes of the local action and the UI has already optimistically updated.
 *
 * Usage:
 *   const { bind } = useRealtime(`org.${orgId}`);
 *   bind('OrganizationUpdated', (payload) => store.applyPatch(payload));
 *
 * Pass `public: true` (or pass a name starting with `public:`) to use a
 * public channel instead. For org-scoped channels the default `private`
 * mode is correct.
 *
 * A channel is SHARED: every store and component watching `org.{id}` gets the
 * same object from Echo. Two consequences drive the bookkeeping below —
 * a handle must remove only the handlers it added, and the channel may only
 * be left once the last handle is gone. Getting either wrong takes realtime
 * down for every other subscriber, silently, until a page reload.
 */

type ChannelMode = 'private' | 'public';

interface RealtimeOptions {
    /**
     * Keep the subscription for the session rather than the mounting
     * component's lifetime. Stores want this: they bind once, guard against
     * re-binding, and must not be torn down by whichever component happened
     * to be mounting when subscribe() ran.
     */
    persist?: boolean;
}

interface RealtimeHandle {
    bind: (eventName: string, handler: (payload: any) => void) => void;
    leave: () => void;
}

/** Live handles per channel, so the last one out turns off the lights. */
const subscriberCounts = new Map<string, number>();

export function useRealtime(
    channelName: string,
    mode: ChannelMode = 'private',
    options: RealtimeOptions = {},
): RealtimeHandle {
    const echo = window.Echo;

    if (!echo) {
        // Echo isn't initialized (e.g., SSR or test) — return a no-op handle so
        // callers don't have to null-check.
        return { bind: () => undefined, leave: () => undefined };
    }

    const channel =
        mode === 'public'
            ? echo.channel(channelName)
            : echo.private(channelName);
    const ownTab = realtimeTabId();
    const countKey = `${mode}:${channelName}`;
    /** This handle's own listeners, so leave() can unbind precisely. */
    const bound: Array<[string, (payload: any) => void]> = [];
    let released = false;

    subscriberCounts.set(countKey, (subscriberCounts.get(countKey) ?? 0) + 1);

    const bind = (eventName: string, handler: (payload: any) => void): void => {
        const wrapped = (payload: any): void => {
            if (payload?.origin_tab && payload.origin_tab === ownTab) {
                return; // self-echo — skip
            }

            handler(payload);
        };

        bound.push([eventName, wrapped]);
        channel.listen(eventName, wrapped);
    };

    const leave = (): void => {
        if (released) {
            return;
        }

        released = true;

        // The callback argument matters: stopListening(event) with no handler
        // unbinds EVERY listener for that event, including other stores'.
        bound.forEach(([eventName, wrapped]) =>
            channel.stopListening(eventName, wrapped),
        );
        bound.length = 0;

        const remaining = (subscriberCounts.get(countKey) ?? 1) - 1;

        if (remaining > 0) {
            subscriberCounts.set(countKey, remaining);

            return;
        }

        subscriberCounts.delete(countKey);

        if (mode === 'public') {
            echo.leaveChannel(channelName);
        } else {
            echo.leaveChannel(`private-${channelName}`);
        }
    };

    // Component-scoped by default. The instance check keeps this usable from
    // a store called outside any component, where onUnmounted would warn and
    // never fire anyway.
    if (!options.persist && getCurrentInstance()) {
        onUnmounted(leave);
    }

    return { bind, leave };
}
