<?php

namespace App\Http\Requests;

use App\Models\Assignment;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validator for POST /api/bulk-assignments. The bulk flow ships its own
 * Request class because the payload shape differs (a `pairs[]` array of
 * {user_id, requirement_id} versus AssignmentRequest's flat fields), and
 * because some rules reach cross-org state — authorising here (instead
 * of in the controller) means cross-org callers see 403, not 422 with
 * leaked foreign-row info. Same pattern as CompletionRequest.
 *
 * Timing lives on the requirement's elements, not the assignment, so the
 * payload is just the pairs[] plus the active window.
 */
class BulkAssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return Gate::check('create', Assignment::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = Auth::user()->org_id;

        return [
            'pairs' => ['required', 'array', 'min:1'],
            'pairs.*.user_id' => [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'pairs.*.requirement_id' => [
                'required',
                'string',
                Rule::exists('requirements', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ],
            'start_date' => ['required', 'date'],
            'end_date' => ['nullable', 'date', 'after_or_equal:start_date'],
        ];
    }
}
