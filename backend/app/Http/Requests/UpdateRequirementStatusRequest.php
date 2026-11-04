<?php

namespace App\Http\Requests;

use App\Models\ComplianceRequirement;
use App\Support\RequirementMessages as M;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * PATCH /api/compliance-requirements/{id}/status (FR-016, BR-004, CMP-16).
 */
class UpdateRequirementStatusRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('updateStatus', $this->requirement());
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'status' => ['bail', 'required', 'string', Rule::in(array_keys(M::STATUSES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'status.required' => M::STATUS_INVALID,
            'status.string' => M::STATUS_INVALID,
            'status.in' => M::STATUS_INVALID,
        ];
    }

    /**
     * @return list<callable>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if (! $validator->errors()->has('status') && $this->input('status') === $this->requirement()->status) {
                    $validator->errors()->add('status', M::STATUS_UNCHANGED);
                }
            },
        ];
    }

    public function requirement(): ComplianceRequirement
    {
        return $this->route('complianceRequirement');
    }
}
