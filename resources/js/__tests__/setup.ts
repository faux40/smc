import { vi } from 'vitest';

/*
 * Global test setup.
 *
 * `@/echo` instantiates a real Reverb/Pusher connection at module load
 * (`new Echo(...)` guarded only on `typeof window`), which throws under
 * happy-dom. Most Pinia stores import the realtime layer transitively, so we
 * mock it once here for every spec. `useRealtime` already no-ops when
 * `window.Echo` is unset, so leaving Echo uninitialized is the correct test
 * posture; we only need `realtimeTabId` to return a stable value.
 */
vi.mock('@/echo', () => ({
    realtimeTabId: () => 'test-tab-uuid',
}));
