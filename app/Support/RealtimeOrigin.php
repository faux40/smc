<?php

namespace App\Support;

/**
 * Reads the per-tab UUID the frontend stamps onto every mutation request
 * via the `X-Origin-Tab` header. Broadcast event payloads include this so
 * the originating tab can self-filter its own echoes.
 */
class RealtimeOrigin
{
    public static function tab(): ?string
    {
        $value = request()->header('X-Origin-Tab');

        return $value === null || $value === '' ? null : (string) $value;
    }
}
