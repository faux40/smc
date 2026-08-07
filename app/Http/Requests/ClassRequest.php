<?php

namespace App\Http\Requests;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Create + update validation for a class. Per-action policy authz happens in
 * the controller. Sub-resources (trainings, enrollments) have their own
 * endpoints + inline validation.
 */
class ClassRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'scheduled_date' => ['required', 'date'],
            // Known in advance for a multi-day class; close-out confirms it
            // rather than asking again. Never a "has this class been closed"
            // signal — that's `completed_at`.
            'completion_date' => ['nullable', 'date'],
            'start_time' => ['nullable', 'date_format:H:i'],
            'end_time' => ['nullable', 'date_format:H:i'],
            'location' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:1000'],
            'instructor' => ['nullable', 'string', 'max:255'],
            'show_signature' => ['boolean'],
            // Refresher guard — enrollees should already hold each topic
            // training's completion. Soft (roster warning), never a block.
            'requires_prior_completion' => ['boolean'],
            'total_hours' => ['nullable', 'numeric', 'min:0'],
            // Reference-only planning counts — never enforced on enrollment.
            'min_students' => ['nullable', 'integer', 'min:0', 'max:9999'],
            'max_students' => [
                'nullable', 'integer', 'min:0', 'max:9999',
                // gte only when a min was given — a bare null min must not
                // invalidate a max-only submission.
                Rule::when($this->filled('min_students'), ['gte:min_students']),
            ],
            'notes' => ['nullable', 'string'],
            // Optional at-create-time training picker (snapshotted on store).
            'training_ids' => ['nullable', 'array'],
            'training_ids.*' => [
                'string',
                Rule::exists('trainings', 'id')
                    ->where('org_id', $this->user()->org_id)
                    ->whereNull('deleted_at'),
            ],
            // Optional at-create-time tags. Classes also inherit their
            // trainings' tags (ClassesController::snapshotTraining), so this
            // exists for tags that have no training to come from — above all
            // the duplicate-class flow, which rebuilds topics from the live
            // library and would otherwise drop tags added to the source by
            // hand. The two sets are unioned.
            'tag_ids' => ['nullable', 'array'],
            'tag_ids.*' => [
                'string',
                Rule::exists('tags', 'id')
                    ->where('org_id', $this->user()->org_id)
                    ->whereNull('deleted_at'),
            ],
            // Optional at-create-time roster (the "duplicate class, include
            // students" flow) — enrolled atomically with the class.
            'user_ids' => ['nullable', 'array'],
            'user_ids.*' => [
                'string',
                Rule::exists('users', 'id')
                    ->where('org_id', $this->user()->org_id)
                    ->whereNull('deleted_at'),
            ],
        ];
    }
}
