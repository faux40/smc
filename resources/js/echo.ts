/*
 * Reverb / Echo wiring + per-tab UUID echo-avoidance.
 *
 * - Every browser tab generates its own UUID, stored in sessionStorage so
 *   it survives page reloads within the same tab but each new tab gets a
 *   fresh one.
 * - That UUID is sent as `X-Origin-Tab` on every Inertia visit (see
 *   `app.ts` wiring) and any raw `fetch`/Pinia-store call should include
 *   it too. Backend events echo it back in their broadcast payloads; the
 *   `useRealtime` composable filters out echoes whose `origin_tab`
 *   matches the current tab's UUID.
 * - This avoids the "I just made a change → I'll redundantly refresh
 *   from my own broadcast" double-apply that pollutes optimistic UIs.
 */

import Echo from 'laravel-echo';
import Pusher from 'pusher-js';

declare global {
    interface Window {
        Pusher: typeof Pusher;
        Echo: Echo<'reverb'>;
    }
}

const TAB_ID_KEY = 'smc.tab_id';

function generateUuid(): string {
    if (typeof crypto !== 'undefined' && typeof crypto.randomUUID === 'function') {
        return crypto.randomUUID();
    }
    return 'tab-' + Math.random().toString(36).slice(2) + Date.now().toString(36);
}

function ensureTabId(): string {
    let id = sessionStorage.getItem(TAB_ID_KEY);
    if (!id) {
        id = generateUuid();
        sessionStorage.setItem(TAB_ID_KEY, id);
    }
    return id;
}

export function realtimeTabId(): string {
    return ensureTabId();
}

window.Pusher = Pusher;

window.Echo = new Echo({
    broadcaster: 'reverb',
    key: import.meta.env.VITE_REVERB_APP_KEY,
    wsHost: import.meta.env.VITE_REVERB_HOST,
    wsPort: Number(import.meta.env.VITE_REVERB_PORT ?? 80),
    wssPort: Number(import.meta.env.VITE_REVERB_PORT ?? 443),
    forceTLS: (import.meta.env.VITE_REVERB_SCHEME ?? 'https') === 'https',
    enabledTransports: ['ws', 'wss'],
});

// Prime the tab UUID on module load so it's available immediately.
ensureTabId();
