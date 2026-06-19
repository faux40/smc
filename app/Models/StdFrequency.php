<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Database\Factories\StdFrequencyFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class StdFrequency extends Model
{
    /** @use HasFactory<StdFrequencyFactory> */
    use BelongsToOrganization, HasFactory, HasUuids, SoftDeletes;

    /**
     * The standard per-org frequency set, ordered longest interval first.
     * Single source of truth: new-org provisioning (CreateNewUser), the dev
     * seeders, and the backfill (BackfillStandardFrequencies) all read this so
     * the canonical list never drifts. Orgs may still add/remove their own.
     *
     * @var array<int, array{name: string, repeat_days: int}>
     */
    public const STANDARD = [
        ['name' => 'Every 5 Years', 'repeat_days' => 1825],
        ['name' => 'Every 4 Years', 'repeat_days' => 1460],
        ['name' => 'Every 3 Years', 'repeat_days' => 1095],
        ['name' => 'Every 2 Years', 'repeat_days' => 730],
        ['name' => 'Annual', 'repeat_days' => 365],
        ['name' => 'Semi-Annual', 'repeat_days' => 180],
        ['name' => 'Quarterly', 'repeat_days' => 90],
        ['name' => 'Monthly', 'repeat_days' => 30],
        ['name' => 'Every 10 days', 'repeat_days' => 10],
    ];

    protected $fillable = ['org_id', 'name', 'repeat_days'];

    protected $casts = [
        'repeat_days' => 'integer',
    ];
}
