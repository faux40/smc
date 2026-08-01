<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use App\Support\Cards\FontFile;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A font file an org uploaded so its card designs print in it (custom-certs
 * C6c).
 *
 * LibreOffice embeds fonts into the exported PDF, but only fonts it can SEE:
 * a family that isn't installed gets substituted at conversion time and the
 * card re-flows at different metrics — which is exactly what ruins a print
 * onto purchased stock. A row here is staged into the print run's own font
 * directory so the converter can see it, without installing anything into
 * the container or leaking one org's licensed font into another's cards.
 *
 * Org-scoped only: the families shipped in the image are config
 * (`cards.supported_fonts`), not rows.
 */
class CardFont extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    /** Formats LibreOffice will load from a font directory. */
    public const FORMATS = ['ttf', 'otf'];

    /**
     * 5 MB. Generous for a single face — a CJK family can approach it — and
     * far below anything that would slow a print run's staging.
     */
    public const MAX_BYTES = 5 * 1024 * 1024;

    protected $fillable = [
        'org_id', 'family', 'family_key', 'original_filename',
        'format', 'path', 'size', 'uploaded_by',
    ];

    protected function casts(): array
    {
        return [
            'size' => 'integer',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function uploader(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by');
    }

    /**
     * Keep the lookup key in step with the family, so a row can never be
     * written whose key doesn't match the comparison used everywhere else.
     */
    protected static function booted(): void
    {
        static::saving(function (self $font): void {
            $font->family_key = FontFile::normalise((string) $font->family);
        });
    }

    /** The filename this font is staged under for the converter. */
    public function stagedFilename(): string
    {
        return $this->id.'.'.$this->format;
    }
}
