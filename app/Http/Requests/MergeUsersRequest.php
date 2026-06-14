<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Combine two users. The actor must be allowed to edit the survivor and to
 * delete the duplicate — the latter gate already blocks Owner targets and
 * self, so an Owner can't be merged away and you can't merge yourself out.
 */
class MergeUsersRequest extends FormRequest
{
    private ?User $survivor = null;

    private ?User $duplicate = null;

    public function authorize(): bool
    {
        $survivor = $this->survivorUser();
        $duplicate = $this->duplicateUser();

        // Null = cross-org / missing (→ 403). Same id is left to the
        // `different` validation rule so self-merge surfaces as a 422.
        if ($survivor === null || $duplicate === null) {
            return false;
        }

        return Gate::allows('update', $survivor) && Gate::allows('delete', $duplicate);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = $this->user()?->org_id;

        $sameOrgUser = Rule::exists('users', 'id')
            ->where('org_id', $orgId)
            ->whereNull('deleted_at');

        return [
            'survivor_id' => ['required', 'uuid', 'different:duplicate_id', $sameOrgUser],
            'duplicate_id' => ['required', 'uuid', $sameOrgUser],
            'fields' => ['array'],
            'fields.*' => [Rule::in(['survivor', 'duplicate'])],
        ];
    }

    /** Org-scoped resolution; cross-org ids resolve to null → 403. */
    public function survivorUser(): ?User
    {
        return $this->survivor ??= User::find($this->input('survivor_id'));
    }

    public function duplicateUser(): ?User
    {
        return $this->duplicate ??= User::find($this->input('duplicate_id'));
    }
}
