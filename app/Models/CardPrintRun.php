<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of printed cards (custom-certs C4): a class topic's completion
 * holders merged into a card design, imposed onto a stock's grid, and filed
 * into the class's documents as a fronts PDF and (optionally) a backs PDF.
 *
 * The row is the record of what was printed — which design *version*, which
 * stock, which start cell — so a card that differs from another can be
 * explained. Like {@see GeneratedDocument} it doesn't soft-delete: the outputs
 * are regenerable and the filed attachments are the lasting artefact.
 */
class CardPrintRun extends Model
{
    use BelongsToOrganization, HasFactory, HasUuids;

    public const STATUSES = ['queued', 'processing', 'done', 'failed'];

    protected $fillable = [
        'org_id', 'class_id', 'class_training_id',
        'card_template_id', 'card_stock_id', 'template_version',
        'start_cell', 'include_backs', 'proof',
        'status', 'error', 'card_count', 'sheet_count',
        'front_path', 'back_path', 'run_stamp',
        'requested_by',
    ];

    protected function casts(): array
    {
        return [
            'template_version' => 'integer',
            'start_cell' => 'integer',
            'include_backs' => 'boolean',
            'proof' => 'boolean',
            'card_count' => 'integer',
            'sheet_count' => 'integer',
        ];
    }

    /** @return BelongsTo<TrainingClass, $this> */
    public function trainingClass(): BelongsTo
    {
        return $this->belongsTo(TrainingClass::class, 'class_id');
    }

    /** @return BelongsTo<ClassTraining, $this> */
    public function topic(): BelongsTo
    {
        return $this->belongsTo(ClassTraining::class, 'class_training_id');
    }

    /** Trashed included: a replaced design still explains an old run. */
    public function template(): BelongsTo
    {
        return $this->belongsTo(CardTemplate::class, 'card_template_id')->withTrashed();
    }

    public function stock(): BelongsTo
    {
        return $this->belongsTo(CardStock::class, 'card_stock_id')->withTrashed();
    }

    /** @return BelongsTo<User, $this> */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }
}
