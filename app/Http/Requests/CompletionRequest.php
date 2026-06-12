<?php

namespace App\Http\Requests;

use App\Models\Completion;
use App\Models\RqmtElement;
use App\Models\Training;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Shared validator for create + update of completions.
 *
 * Rules per v15 spec:
 * - user_id required (create only), same-org
 * - module_type required (create only), in ALLOWED_MODULE_TYPES
 * - module_id required (create only), same-org row of that type
 * - completion_date required
 * - rqmt_element_ids: required array, min 1, every id must:
 *     a) belong to the same org, and
 *     b) have module_type + module_id matching the completion's
 *        (cannot satisfy a Forklift element with a Hazmat completion)
 *
 * The application layer enforces the min:1 link — the schema permits
 * orphans so a future "credit for unassigned" loosening doesn't need a
 * migration.
 */
class CompletionRequest extends FormRequest
{
    /**
     * Whitelist of module types that can back a completion. Mirrors the
     * RqmtElementRequest whitelist; append in lockstep as future modules
     * land.
     */
    public const ALLOWED_MODULE_TYPES = [
        Training::class,
    ];

    public function authorize(): bool
    {
        // Run policy authz here (not in the controller) so 403 fires before
        // validation. Some of our rules reference cross-org state (elements,
        // module rows) and would otherwise return 422 for unauthorized
        // callers — wrong status and a small info leak.
        if ($this->isMethod('post')) {
            return Gate::check('create', Completion::class);
        }
        /** @var Completion|null $completion */
        $completion = $this->route('completion');

        return $completion !== null && Gate::check('update', $completion);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $orgId = Auth::user()->org_id;
        $isCreate = $this->isMethod('post');

        $rules = [
            'completion_date' => ['required', 'date'],
            'certification_date' => ['nullable', 'date'],
            'expire_date' => ['nullable', 'date'],
            'cert_ident' => ['nullable', 'string', 'max:255'],
            'hours' => ['nullable', 'numeric', 'min:0', 'max:9999'],
            'notes' => ['nullable', 'string'],
            'rqmt_element_ids' => ['required', 'array', 'min:1'],
            'rqmt_element_ids.*' => ['string', 'distinct'],
        ];

        if ($isCreate) {
            $rules['user_id'] = [
                'required',
                'string',
                Rule::exists('users', 'id')
                    ->where('org_id', $orgId)
                    ->whereNull('deleted_at'),
            ];
            $rules['module_type'] = ['required', 'string', Rule::in(self::ALLOWED_MODULE_TYPES)];
            $rules['module_id'] = ['required', 'string'];
        }

        return $rules;
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $v): void {
            $data = $v->getData();
            $orgId = Auth::user()->org_id;

            // Resolve the completion's module identity. On create it comes
            // from the payload; on update we pin it from the existing row.
            if ($this->isMethod('post')) {
                $moduleType = $data['module_type'] ?? null;
                $moduleId = $data['module_id'] ?? null;

                if ($moduleType && $moduleId && in_array($moduleType, self::ALLOWED_MODULE_TYPES, true)) {
                    /** @var class-string<Model> $moduleType */
                    $row = $moduleType::query()->withoutGlobalScope('organization')->find($moduleId);
                    if ($row === null || $row->org_id !== $orgId) {
                        $v->errors()->add('module_id', 'The selected module must belong to your organization.');

                        return;
                    }
                }
            } else {
                /** @var Completion|null $completion */
                $completion = $this->route('completion');
                $moduleType = $completion?->module_type;
                $moduleId = $completion?->module_id;
            }

            $ids = (array) ($data['rqmt_element_ids'] ?? []);
            if (! $ids) {
                return;
            }

            $elements = RqmtElement::query()
                ->withoutGlobalScope('organization')
                ->whereIn('id', $ids)
                ->get();

            // Existence — assertion that every requested id resolved.
            if ($elements->count() !== count(array_unique($ids))) {
                $v->errors()->add('rqmt_element_ids', 'One or more elements could not be found.');

                return;
            }

            foreach ($elements as $element) {
                if ($element->org_id !== $orgId) {
                    $v->errors()->add('rqmt_element_ids', 'Every element must belong to your organization.');

                    return;
                }
                if ($moduleType && $element->module_type !== $moduleType) {
                    $v->errors()->add(
                        'rqmt_element_ids',
                        'Every element must point at the same module as the completion.',
                    );

                    return;
                }
                if ($moduleId && $element->module_id !== $moduleId) {
                    $v->errors()->add(
                        'rqmt_element_ids',
                        'Every element must point at the same module as the completion.',
                    );

                    return;
                }
            }
        });
    }
}
