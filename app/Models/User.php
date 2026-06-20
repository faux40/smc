<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Models\Concerns\HasAttachments;
use App\Models\Concerns\HasComments;
use App\Models\Concerns\HasTags;
use App\Support\PersonName;
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
    'notes',
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
     * @return BelongsTo<Organization, $this>
     */
    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'org_id');
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
     * Bundle the 5 name columns into the shared formatter. All name
     * arrangements (full / sortable / short / initials) derive from here so
     * the formatting logic lives in exactly one place.
     */
    public function personName(): PersonName
    {
        return new PersonName(
            $this->prefix_name,
            $this->f_name,
            $this->m_name,
            $this->l_name,
            $this->suffix_name,
        );
    }

    /**
     * Full display name in natural order ("Dr. Ada Augusta Lovelace III").
     * Appended so existing consumers (templates, broadcasts, tests) keep working.
     */
    protected function name(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->personName()->full());
    }

    /**
     * Sortable, last-name-first name for lists and pickers
     * ("Lovelace, Ada Augusta").
     */
    protected function sortName(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->personName()->sortable());
    }

    /**
     * Compact first + last name ("Ada Lovelace").
     */
    protected function shortName(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->personName()->short());
    }

    /**
     * Avatar initials ("AL").
     */
    protected function initials(): Attribute
    {
        return Attribute::make(get: fn (): string => $this->personName()->initials());
    }
}
