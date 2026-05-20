<?php

namespace App\Auth;

use Illuminate\Auth\EloquentUserProvider;
use Illuminate\Support\Str;

/**
 * EloquentUserProvider that tolerates malformed identifiers.
 *
 * Users have UUID primary keys. A stale or tampered "remember me" recaller
 * cookie can carry a non-UUID identifier; the default provider passes it
 * straight into `where id = ?`, and Postgres rejects the cast (SQLSTATE
 * 22P02), surfacing as a 500 on an otherwise-anonymous request. Guarding the
 * lookups lets a bad identifier be treated as "not remembered" — the request
 * proceeds unauthenticated to the login page instead of crashing.
 */
class SafeEloquentUserProvider extends EloquentUserProvider
{
    public function retrieveById($identifier)
    {
        if (! $this->isValidIdentifier($identifier)) {
            return null;
        }

        return parent::retrieveById($identifier);
    }

    public function retrieveByToken($identifier, #[\SensitiveParameter] $token)
    {
        if (! $this->isValidIdentifier($identifier)) {
            return null;
        }

        return parent::retrieveByToken($identifier, $token);
    }

    /**
     * UUID-keyed users only resolve for well-formed UUID identifiers.
     */
    protected function isValidIdentifier(mixed $identifier): bool
    {
        return is_string($identifier) && Str::isUuid($identifier);
    }
}
