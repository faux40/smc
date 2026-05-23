import type { AxiosError, AxiosInstance } from 'axios';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { makeCsrf419Handler } from '@/lib/csrf';

function metaToken(): string | undefined {
    return document.querySelector<HTMLMetaElement>('meta[name="csrf-token"]')
        ?.content;
}

/** A 419 axios error with a config carrying a (stale) token header. */
function error419(): AxiosError {
    return {
        isAxiosError: true,
        response: { status: 419 },
        config: { headers: { 'X-CSRF-TOKEN': 'stale' }, url: '/x' },
    } as unknown as AxiosError;
}

describe('makeCsrf419Handler', () => {
    beforeEach(() => {
        document.head.innerHTML =
            '<meta name="csrf-token" content="stale">';
    });

    it('refreshes the token, updates the meta, and retries once on 419', async () => {
        const client = vi.fn(() => Promise.resolve({ data: 'ok' })) as unknown as AxiosInstance & ReturnType<typeof vi.fn>;
        client.get = vi.fn().mockResolvedValue({ data: { token: 'fresh' } });

        const handler = makeCsrf419Handler(client);
        const result = await handler(error419());

        expect(client.get).toHaveBeenCalledWith('/csrf-token');
        expect(metaToken()).toBe('fresh');
        // Retried with the refreshed header.
        const retriedConfig = (client as unknown as ReturnType<typeof vi.fn>).mock
            .calls[0][0];
        expect(retriedConfig.headers.get('X-CSRF-TOKEN')).toBe('fresh');
        expect(result).toEqual({ data: 'ok' });
    });

    it('passes non-419 errors straight through', async () => {
        const client = vi.fn() as unknown as AxiosInstance & ReturnType<typeof vi.fn>;
        client.get = vi.fn();
        const handler = makeCsrf419Handler(client);

        const err = { response: { status: 500 }, config: {} } as AxiosError;
        await expect(handler(err)).rejects.toBe(err);
        expect(client.get).not.toHaveBeenCalled();
    });

    it('does not retry a request that was already retried (no loop)', async () => {
        const client = vi.fn() as unknown as AxiosInstance & ReturnType<typeof vi.fn>;
        client.get = vi.fn();
        const handler = makeCsrf419Handler(client);

        const err = {
            response: { status: 419 },
            config: { __csrfRetried: true },
        } as unknown as AxiosError;

        await expect(handler(err)).rejects.toBe(err);
        expect(client.get).not.toHaveBeenCalled();
    });

    it('surfaces the original 419 if the token refresh fails', async () => {
        const client = vi.fn() as unknown as AxiosInstance & ReturnType<typeof vi.fn>;
        client.get = vi.fn().mockRejectedValue(new Error('network'));
        const handler = makeCsrf419Handler(client);

        const err = error419();
        await expect(handler(err)).rejects.toBe(err);
        expect(client).not.toHaveBeenCalled(); // never retried
    });
});
