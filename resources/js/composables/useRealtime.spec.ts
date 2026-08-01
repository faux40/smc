import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { defineComponent } from 'vue';
import { useRealtime } from '@/composables/useRealtime';

vi.mock('@/echo', () => ({ realtimeTabId: () => 'this-tab' }));

/**
 * A stand-in for Echo that behaves like pusher-js where it matters: one
 * channel object per name (they are shared), many callbacks per event, and
 * unbinding either one callback or all of them.
 */
function fakeEcho() {
    const channels = new Map<string, ReturnType<typeof makeChannel>>();
    const left: string[] = [];

    function makeChannel(name: string) {
        const handlers = new Map<string, Array<(p: unknown) => void>>();

        return {
            name,
            handlers,
            listen(event: string, cb: (p: unknown) => void) {
                handlers.set(event, [...(handlers.get(event) ?? []), cb]);

                return this;
            },
            stopListening(event: string, cb?: (p: unknown) => void) {
                if (cb === undefined) {
                    handlers.delete(event);
                } else {
                    handlers.set(
                        event,
                        (handlers.get(event) ?? []).filter((h) => h !== cb),
                    );
                }

                return this;
            },
            emit(event: string, payload: unknown) {
                (handlers.get(event) ?? []).forEach((h) => h(payload));
            },
        };
    }

    return {
        left,
        channelFor: (name: string) => channels.get(name),
        private(name: string) {
            if (!channels.has(name)) {
                channels.set(name, makeChannel(name));
            }

            return channels.get(name)!;
        },
        channel(name: string) {
            return this.private(name);
        },
        leaveChannel(name: string) {
            left.push(name);
        },
    };
}

/*
 * Subscriber counts live for the page session, so each test uses its own
 * channel — exactly as two orgs would — rather than reaching into module
 * state to reset it.
 */
let channelSeq = 0;
const nextChannel = () => `org.${++channelSeq}`;

/** Mounts a component that subscribes for its own lifetime. */
function mountSubscriber(
    channel: string,
    event: string,
    handler: (p: unknown) => void,
) {
    return mount(
        defineComponent({
            setup() {
                const { bind } = useRealtime(channel);
                bind(event, handler);

                return () => null;
            },
        }),
    );
}

describe('useRealtime', () => {
    beforeEach(() => {
        window.Echo = fakeEcho() as never;
    });

    it('delivers events to every subscriber on a shared channel', () => {
        const channel = nextChannel();
        const a = vi.fn();
        const b = vi.fn();
        mountSubscriber(channel, 'ClassChanged', a);
        mountSubscriber(channel, 'ClassChanged', b);

        (window.Echo as never as ReturnType<typeof fakeEcho>)
            .channelFor(channel)!
            .emit('ClassChanged', { class_id: 'c1' });

        expect(a).toHaveBeenCalledTimes(1);
        expect(b).toHaveBeenCalledTimes(1);
    });

    it('leaves other subscribers listening when one unmounts', () => {
        /*
         * The bug this exists for: stopListening(event) with no callback drops
         * EVERY handler for that event, and leaveChannel kills the shared
         * channel. Ten stores share org.{id}, so one component unmounting took
         * realtime down for all of them — and their subscribe() guards then
         * refused to re-bind, so nothing updated again until a page reload.
         */
        const channel = nextChannel();
        const survivor = vi.fn();
        const leaver = vi.fn();
        mountSubscriber(channel, 'ClassChanged', survivor);
        const second = mountSubscriber(channel, 'ClassChanged', leaver);

        second.unmount();

        const echo = window.Echo as never as ReturnType<typeof fakeEcho>;
        echo.channelFor(channel)!.emit('ClassChanged', { class_id: 'c1' });

        expect(survivor).toHaveBeenCalledTimes(1);
        expect(leaver).not.toHaveBeenCalled();
        expect(echo.left).toEqual([]);
    });

    it('leaves the channel once the last subscriber is gone', () => {
        const channel = nextChannel();
        const first = mountSubscriber(channel, 'ClassChanged', vi.fn());
        const second = mountSubscriber(channel, 'ClassChanged', vi.fn());

        first.unmount();
        expect(
            (window.Echo as never as ReturnType<typeof fakeEcho>).left,
        ).toEqual([]);

        second.unmount();
        expect(
            (window.Echo as never as ReturnType<typeof fakeEcho>).left,
        ).toEqual([`private-${channel}`]);
    });

    it('keeps a persistent subscription alive across unmounts', () => {
        // Stores subscribe once for the session and guard against re-binding,
        // so their subscription must not be tied to whichever component
        // happened to be mounting when subscribe() ran.
        const channel = nextChannel();
        const storeHandler = vi.fn();
        const holder = mount(
            defineComponent({
                setup() {
                    const { bind } = useRealtime(channel, 'private', {
                        persist: true,
                    });
                    bind('ClassChanged', storeHandler);

                    return () => null;
                },
            }),
        );

        holder.unmount();

        const echo = window.Echo as never as ReturnType<typeof fakeEcho>;
        echo.channelFor(channel)!.emit('ClassChanged', { class_id: 'c1' });

        expect(storeHandler).toHaveBeenCalledTimes(1);
        expect(echo.left).toEqual([]);
    });

    it('still skips this tab’s own echo', () => {
        const channel = nextChannel();
        const handler = vi.fn();
        mountSubscriber(channel, 'ClassChanged', handler);

        (window.Echo as never as ReturnType<typeof fakeEcho>)
            .channelFor(channel)!
            .emit('ClassChanged', { class_id: 'c1', origin_tab: 'this-tab' });

        expect(handler).not.toHaveBeenCalled();
    });

    it('is a no-op when Echo never initialised', () => {
        window.Echo = undefined;

        expect(() =>
            mountSubscriber(nextChannel(), 'ClassChanged', vi.fn()),
        ).not.toThrow();
    });
});
