/*
 * TEMP DIAGNOSTIC — toasts every inbound org-channel Reverb event so
 * we can confirm the broadcast path is live end-to-end. Remove this
 * file (and the call site in useRealtime.ts) once Reverb traffic is
 * no longer being monitored.
 */

import { useErrorStore } from '@/stores/errors';

function payloadPreview(payload: unknown): string {
    try {
        const json = JSON.stringify(payload);
        if (!json) return '';
        return json.length > 140 ? json.slice(0, 140) + '…' : json;
    } catch {
        return String(payload);
    }
}

export function debugRealtimeEvent(
    channel: string,
    eventName: string,
    payload: unknown,
): void {
    const store = useErrorStore();
    store.report({
        context: 'temp:realtime-monitor',
        message: `${channel} · ${eventName} — ${payloadPreview(payload)}`,
        surface: 'toast',
    });
}
