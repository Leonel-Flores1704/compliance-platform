<?php

namespace App\Http\Requests;

use App\Models\Organization;
use App\Support\OrganizationMessages as M;
use Closure;
use Illuminate\Auth\Access\Response;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Validation for organization registration (FR-001, ADR-001, CMP-14).
 * This class is the source of truth; the React form repeats the same rules for instant feedback.
 */
class StoreOrganizationRequest extends FormRequest
{
    public function authorize(): Response
    {
        return Gate::inspect('create', Organization::class);
    }

    protected function prepareForValidation(): void
    {
        $activities = $this->input('business_activities');

        if (is_array($activities)) {
            // Duplicated codes are merged instead of rejected.
            $this->merge(['business_activities' => array_values(array_unique($activities, SORT_REGULAR))]);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'legal_name' => ['bail', 'required', 'string', 'between:3,150', $this->uniqueLegalName()],
            'organization_type' => ['bail', 'required', 'string', Rule::exists('organization_types', 'code')],
            'industry' => ['bail', 'required', 'string', Rule::exists('industries', 'code')],
            'state' => ['bail', 'required', 'string', Rule::exists('mexican_states', 'code')],
            'municipality' => ['bail', 'required', 'string', 'between:2,100'],
            'employees_count' => ['bail', 'required', $this->wholeNumberBetween(1, 1_000_000)],
            'business_activities' => ['bail', 'required', 'array', 'max:10', $this->validActivities()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'legal_name.required' => M::LEGAL_NAME_REQUIRED,
            'legal_name.string' => M::LEGAL_NAME_LENGTH,
            'legal_name.between' => M::LEGAL_NAME_LENGTH,
            'organization_type.required' => M::ORGANIZATION_TYPE_REQUIRED,
            'organization_type.string' => M::ORGANIZATION_TYPE_INVALID,
            'organization_type.exists' => M::ORGANIZATION_TYPE_INVALID,
            'industry.required' => M::INDUSTRY_REQUIRED,
            'industry.string' => M::INDUSTRY_INVALID,
            'industry.exists' => M::INDUSTRY_INVALID,
            'state.required' => M::STATE_REQUIRED,
            'state.string' => M::STATE_REQUIRED,
            'state.exists' => M::STATE_REQUIRED,
            'municipality.required' => M::MUNICIPALITY_REQUIRED,
            'municipality.string' => M::MUNICIPALITY_LENGTH,
            'municipality.between' => M::MUNICIPALITY_LENGTH,
            'employees_count.required' => M::EMPLOYEES_REQUIRED,
            'business_activities.required' => M::ACTIVITIES_REQUIRED,
            'business_activities.array' => M::ACTIVITIES_REQUIRED,
            'business_activities.max' => M::ACTIVITIES_MAX,
        ];
    }

    /**
     * The validated data in the shape stored in the organizations table.
     *
     * @return array<string, mixed>
     */
    public function organizationData(): array
    {
        $data = $this->validated();
        $data['employees_count'] = (int) $data['employees_count'];

        return $data;
    }

    private function uniqueLegalName(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $exists = DB::table('organizations')
                ->whereRaw('lower(legal_name) = lower(?)', [$value])
                ->exists();

            if ($exists) {
                $fail(M::LEGAL_NAME_TAKEN);
            }
        };
    }

    private function wholeNumberBetween(int $min, int $max): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($min, $max): void {
            $isWhole = is_int($value) || (is_string($value) && preg_match('/^\d+$/', $value) === 1);

            if (! $isWhole || (int) $value < $min || (int) $value > $max) {
                $fail(M::EMPLOYEES_INVALID);
            }
        };
    }

    private function validActivities(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            $codes = array_filter($value, 'is_string');

            if (count($codes) !== count($value)) {
                $fail(M::ACTIVITIES_INVALID);

                return;
            }

            $known = DB::table('business_activities')->whereIn('code', $codes)->count();

            if ($known !== count($codes)) {
                $fail(M::ACTIVITIES_INVALID);
            }
        };
    }
}
