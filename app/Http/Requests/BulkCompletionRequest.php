<?php

namespace App\Http\Requests;

use App\Models\Completion;
use App\Models\Training;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Bulk completion recording: one training × many users (F8), for the "12
 * people did the tailgate talk, here's the paper sheet" flow — without the
 * full class workflow.
 *
 * The completion fields (dates / cert / hours / notes / element links) are
 * shared with the single CompletionRequest via completionFieldRules() +
 * validateElementsBelongToModule(); only the identity differs — a flat
 * training_id plus user_ids[] instead of module_type/module_id + user_id.
 * The module is always a Training here (recalc is training-based).
 */
class BulkCompletionRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Same gate as the single create — the CompletionPolicy widened
        // create to Manager+ (Phase 13.2).
        return Gate::check('create', Completion::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = Auth::user()->org_id;

        return [
            'user_ids' => ['required', 'array', 'min:1'],
            'user_ids.*' => ['required', 'string', 'uuid'],
            // Org-scoped like BulkTrainingAssignmentRequest so a foreign id
            // fails validation (422) rather than leaking through to the loop.
            'training_id' => [
                'required', 'string', 'uuid',
                Rule::exists('trainings', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            ...CompletionRequest::completionFieldRules(),
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $orgId = Auth::user()->org_id;

            CompletionRequest::validateElementsBelongToModule(
                $v,
                $orgId,
                Training::class,
                $data['training_id'] ?? null,
                (array) ($data['rqmt_element_ids'] ?? []),
            );
        });
    }
}
