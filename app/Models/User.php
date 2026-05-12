<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Spatie\Permission\Traits\HasRoles;

#[Fillable([
    'org_id',
    'f_name',
    'm_name',
    'l_name',
    'prefix_name',
    'suffix_name',
    'email',
    'password',
    'status',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasFactory, HasRoles, HasUuids, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

    /**
     * Computed display name. Appears in toArray() / Inertia props so
     * existing consumers (templates, broadcasts, tests) keep working.
     *
     * @var list<string>
     */
    protected $appends = ['name'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /**
     * Composes the 5 name fields into a display string. Empty segments
     * are skipped and consecutive spaces are collapsed.
     */
    protected function name(): Attribute
    {
        return Attribute::make(
            get: fn (): string => trim(preg_replace('/\s+/', ' ', implode(' ', array_filter([
                $this->prefix_name,
                $this->f_name,
                $this->m_name,
                $this->l_name,
                $this->suffix_name,
            ], fn ($v) => is_string($v) && $v !== ''))) ?? ''),
        );
    }
}
