<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One generated document run (Phase D2): a doc template merged with the
 * org's data for a (location, department) variation, producing an
 * editable DOCX/ODT + a client-ready PDF on the linode disk. The merge
 * snapshot preserves exactly what was filled in. No SoftDeletes —
 * deleting removes the files too (outputs are regenerable).
 */
class GeneratedDocument extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    public const STATUSES = ['queued', 'processing', 'done', 'failed'];

    protected $fillable = [
        'org_id', 'doc_template_id', 'requested_by', 'location', 'department',
        'status', 'error', 'filename', 'merged_path', 'pdf_path', 'merge_snapshot',
    ];

    protected function casts(): array
    {
        return [
            'merge_snapshot' => 'array',
        ];
    }

    public function template(): BelongsTo
    {
        return $this->belongsTo(DocTemplate::class, 'doc_template_id')->withTrashed();
    }

    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
