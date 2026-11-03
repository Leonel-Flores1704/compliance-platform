<?php

namespace App\Http\Requests;

use App\Models\ComplianceRequirement;
use App\Models\User;
use App\Support\RequirementMessages as M;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation for compliance requirement creation (FR-006, BR-004, CMP-15).
 * This class is the source of truth; the React form repeats the same rules for instant feedback.
 */
class StoreComplianceRequirementRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', ComplianceRequirement::class);
    }

    protected function prepareForValidation(): void
    {
        // The initial status defaults to Pending when it is not sent.
        if (! $this->exists('status')) {
            $this->merge(['status' => 'pending']);
        }

        $documents = $this->input('required_documents');
        if (is_array($documents)) {
            $this->merge(['required_documents' => array_map(
                fn ($document) => is_array($document)
                    ? array_map(fn ($value) => is_string($value) ? trim($value) : $value, $document)
                    : $document,
                $documents,
            )]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['bail', 'required', 'string', 'between:3,150'],
            'description' => ['bail', 'required', 'string', 'between:10,2000'],
            'regulation' => ['bail', 'required', 'string', 'between:2,150'],
            'deadline' => ['bail', 'required', 'string', 'date_format:Y-m-d'],
            'required_documents' => ['bail', 'required', 'array', 'list'],
            'required_documents.*' => ['bail', 'array'],
            'required_documents.*.name' => ['bail', 'required', 'string', 'max:150'],
            'required_documents.*.category' => ['bail', 'required', 'string', Rule::exists('document_categories', 'code')],
            'responsible_user_id' => ['bail', 'required', $this->activeUserOfOrganization()],
            'status' => ['bail', 'required', 'string', Rule::in(array_keys(M::STATUSES))],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'name.required' => M::NAME_REQUIRED,
            'name.string' => M::NAME_LENGTH,
            'name.between' => M::NAME_LENGTH,
            'description.required' => M::DESCRIPTION_REQUIRED,
            'description.string' => M::DESCRIPTION_LENGTH,
            'description.between' => M::DESCRIPTION_LENGTH,
            'regulation.required' => M::REGULATION_REQUIRED,
            'regulation.string' => M::REGULATION_LENGTH,
            'regulation.between' => M::REGULATION_LENGTH,
            'deadline.required' => M::DEADLINE_REQUIRED,
            'deadline.string' => M::DEADLINE_INVALID,
            'deadline.date_format' => M::DEADLINE_INVALID,
            'required_documents.required' => M::DOCUMENTS_REQUIRED,
            'required_documents.array' => M::DOCUMENTS_REQUIRED,
            'required_documents.list' => M::DOCUMENTS_REQUIRED,
            'required_documents.*.array' => M::DOCUMENT_INCOMPLETE,
            'required_documents.*.name.required' => M::DOCUMENT_INCOMPLETE,
            'required_documents.*.name.string' => M::DOCUMENT_INCOMPLETE,
            'required_documents.*.name.max' => M::DOCUMENT_NAME_LENGTH,
            'required_documents.*.category.required' => M::DOCUMENT_INCOMPLETE,
            'required_documents.*.category.string' => M::DOCUMENT_INCOMPLETE,
            'required_documents.*.category.exists' => M::DOCUMENT_INCOMPLETE,
            'responsible_user_id.required' => M::RESPONSIBLE_REQUIRED,
            'status.required' => M::STATUS_INVALID,
            'status.string' => M::STATUS_INVALID,
            'status.in' => M::STATUS_INVALID,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function requirementData(): array
    {
        $data = $this->validated();

        return [
            'name' => $data['name'],
            'description' => $data['description'],
            'regulation' => $data['regulation'],
            'deadline' => $data['deadline'],
            'responsible_user_id' => (int) $data['responsible_user_id'],
            'status' => $data['status'],
        ];
    }

    /**
     * @return list<array{name: string, category: string}>
     */
    public function requiredDocumentsData(): array
    {
        return array_map(
            fn (array $document) => ['name' => $document['name'], 'category' => $document['category']],
            $this->validated('required_documents'),
        );
    }

    private function activeUserOfOrganization(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $isId = is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1);

            $valid = $isId && User::query()
                ->whereKey((int) $value)
                ->where('organization_id', $this->user()->organization_id)
                ->where('is_active', true)
                ->exists();

            if (! $valid) {
                $fail(M::RESPONSIBLE_INVALID);
            }
        };
    }

    /**
     * Deadlines must be real calendar dates (date_format accepts 2026-02-30 in some PHP versions).
     */
    public function after(): array
    {
        return [
            function ($validator): void {
                $deadline = $this->input('deadline');
                if (! is_string($deadline) || $validator->errors()->has('deadline')) {
                    return;
                }
                [$year, $month, $day] = array_map('intval', explode('-', $deadline) + [0, 0, 0]);
                if (! checkdate($month, $day, $year)) {
                    $validator->errors()->add('deadline', M::DEADLINE_INVALID);
                }
            },
        ];
    }
}
