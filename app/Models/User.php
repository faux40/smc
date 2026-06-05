<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasTags;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
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
    'department',
    'location',
    'job_title',
    'employee_number',
    'supervisor_id',
    'start_date',
    'end_date',
])]
#[Hidden(['password', 'two_factor_secret', 'two_factor_recovery_codes', 'remember_token'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use BelongsToOrganization, HasAttachments, HasComments, HasFactory, HasRoles, HasTags, HasUuids, Notifiable, SoftDeletes, TwoFactorAuthenticatable;

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
            'start_date' => 'date',
            'end_date' => 'date',
            'preferences' => 'array',
        ];
    }

    /**
     * This user's supervisor (another user in the same org), if set.
     *
     * @return BelongsTo<User, $this>
     */
    public function supervisor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'supervisor_id');
    }

    /**
     * Users who report to this one. Drives future grouping/searching.
     *
     * @return HasMany<User, $this>
     */
    public function reports(): HasMany
    {
        return $this->hasMany(User::class, 'supervisor_id');
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
