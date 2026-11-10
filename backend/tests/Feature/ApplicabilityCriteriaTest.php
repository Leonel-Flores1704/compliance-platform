<?php

use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Organization;
use App\Models\User;
use App\Support\CriteriaMessages as M;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    // Demo organization of the acceptance criteria: Manufacturing, 120 employees.
    $this->organization = Organization::query()->create([
        'legal_name' => 'Aceros del Guadiana, S.A. de C.V.', 'organization_type' => 'private_company',
        'industry' => 'manufacturing', 'state' => '10', 'municipality' => 'Durango',
        'employees_count' => 120, 'business_activities' => ['31-33', '43'],
    ]);
    $other = Organization::query()->create([
        'legal_name' => 'Otra Empresa, S.A. de C.V.', 'organization_type' => 'cooperative',
        'industry' => 'retail', 'state' => '09', 'municipality' => 'Coyoacan',
        'employees_count' => 10, 'business_activities' => ['46'],
    ]);

    $makeUser = fn (string $role, string $email, Organization $org) => User::query()->create([
        'organization_id' => $org->id, 'name' => ucwords(str_replace('_', ' ', $role)),
        'email' => $email, 'password' => 'password', 'role' => $role,
    ]);
    $this->staff = $makeUser('compliance_staff', 'staff@example.test', $this->organization);
    $this->admin = $makeUser('administrator', 'admin@example.test', $this->organization);
    $this->employee = $makeUser('general_employee', 'employee@example.test', $this->organization);
    $this->outsider = $makeUser('compliance_staff', 'outsider@example.test', $other);

    $this->makeRequirement = fn (string $name) => ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id, 'name' => $name,
        'description' => 'Requirement used by the applicability tests.', 'regulation' => 'NOM-000-TEST',
        'deadline' => '2026-12-31', 'responsible_user_id' => $this->staff->id, 'status' => 'pending',
        'applicability_criteria' => ['all' => []], 'applies' => true,
        'notification_periods' => [30, 15, 7, 1], 'created_by' => $this->staff->id,
    ]);
});

function criteriaUrl(ComplianceRequirement $requirement): string
{
    return "/api/compliance-requirements/{$requirement->id}/criteria";
}

const MANUFACTURING_51 = ['all' => [
    ['field' => 'industry', 'op' => 'in', 'value' => ['manufacturing']],
    ['field' => 'employees_count', 'op' => 'gte', 'value' => 51],
]];

const MANUFACTURING_251 = ['all' => [
    ['field' => 'industry', 'op' => 'in', 'value' => ['manufacturing']],
    ['field' => 'employees_count', 'op' => 'gte', 'value' => 251],
]];

describe('acceptance criteria with the demo organization (Manufacturing, 120 employees)', function (): void {
    it('stores the criteria exactly in the ADR-021 format', function (): void {
        $requirement = ($this->makeRequirement)('Large manufacturing safety program');

        $this->actingAs($this->staff)
            ->putJson(criteriaUrl($requirement), ['use_criteria' => true, 'criteria' => MANUFACTURING_51])
            ->assertOk()
            ->assertJsonPath('message', 'Applicability criteria saved. The requirement list has been updated.')
            ->assertJsonPath('data.applicability_criteria', MANUFACTURING_51)
            ->assertJsonPath('data.applies', true);

        // PostgreSQL jsonb does not keep key order, so the comparison ignores it.
        expect($requirement->fresh()->applicability_criteria)->toEqual(MANUFACTURING_51);
    });

    it('shows the requirement with gte 51 and hides the one with gte 251', function (): void {
        $applies = ($this->makeRequirement)('Manufacturing with 51+ employees');
        $notApplies = ($this->makeRequirement)('Manufacturing with 251+ employees');

        $this->actingAs($this->staff)->putJson(criteriaUrl($applies), ['criteria' => MANUFACTURING_51])->assertOk();
        $this->actingAs($this->staff)->putJson(criteriaUrl($notApplies), ['criteria' => MANUFACTURING_251])
            ->assertOk()
            ->assertJsonPath('data.applies', false);

        $this->actingAs($this->staff)->getJson('/api/compliance-requirements')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', 'Manufacturing with 51+ employees');

        // Not applicable requirements stay stored.
        expect(ComplianceRequirement::query()->count())->toBe(2);
        $this->actingAs($this->staff)->getJson('/api/compliance-requirements?include_not_applicable=1')
            ->assertJsonCount(2, 'data');
    });

    it('shows the second requirement after the organization changes to 300 employees, without manual action', function (): void {
        $applies = ($this->makeRequirement)('Manufacturing with 51+ employees');
        $notApplies = ($this->makeRequirement)('Manufacturing with 251+ employees');
        $this->actingAs($this->staff)->putJson(criteriaUrl($applies), ['criteria' => MANUFACTURING_51])->assertOk();
        $this->actingAs($this->staff)->putJson(criteriaUrl($notApplies), ['criteria' => MANUFACTURING_251])->assertOk();

        $this->organization->update(['employees_count' => 300]);

        expect($notApplies->fresh()->applies)->toBeTrue();
        $this->actingAs($this->staff)->getJson('/api/compliance-requirements')
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.name', 'Manufacturing with 251+ employees');

        $audit = AuditLog::query()->where('action', 'requirement.applicability_changed')->where('resource_id', $notApplies->id)->orderByDesc('id')->first();
        expect($audit->user_id)->toBeNull()
            ->and($audit->metadata)->toEqual(['from' => false, 'to' => true, 'reason' => 'organization_updated']);

        // Back to 120 employees: it disappears again but stays stored.
        $this->organization->update(['employees_count' => 120]);
        expect($notApplies->fresh()->applies)->toBeFalse();
    });

    it('evaluates the requirements when a new organization is registered (CMP-14)', function (): void {
        $requirement = ($this->makeRequirement)('Healthcare only');
        $requirement->forceFill(['applicability_criteria' => ['all' => [['field' => 'industry', 'op' => 'eq', 'value' => 'healthcare']]]])->saveQuietly();

        // A save of the organization (any field) re-evaluates its requirements.
        $this->organization->update(['municipality' => 'Gomez Palacio']);

        expect($requirement->fresh()->applies)->toBeFalse();
    });
});

describe('criteria maintenance', function (): void {
    it('allows Administrators too (ADR-005, function 3)', function (): void {
        $requirement = ($this->makeRequirement)('Admin edit');

        $this->actingAs($this->admin)->putJson(criteriaUrl($requirement), ['criteria' => MANUFACTURING_51])->assertOk();
    });

    it('stores {"all": []} and applies to every organization when criteria are not used', function (): void {
        $requirement = ($this->makeRequirement)('Applies to everyone');
        $this->actingAs($this->staff)->putJson(criteriaUrl($requirement), ['criteria' => MANUFACTURING_251])->assertOk();

        $this->actingAs($this->staff)
            ->putJson(criteriaUrl($requirement), ['use_criteria' => false])
            ->assertOk()
            ->assertJsonPath('data.applicability_criteria', ['all' => []])
            ->assertJsonPath('data.applies', true);
    });

    it('writes requirement.updated with the user and the old and new criteria', function (): void {
        $requirement = ($this->makeRequirement)('Audited');

        $this->actingAs($this->staff)->putJson(criteriaUrl($requirement), ['criteria' => MANUFACTURING_51])->assertOk();

        $audit = AuditLog::query()->where('action', 'requirement.updated')->sole();
        expect($audit->user_id)->toBe($this->staff->id)
            ->and($audit->resource_id)->toBe($requirement->id)
            ->and($audit->metadata['changed_fields']['applicability_criteria']['old'])->toEqual(['all' => []])
            ->and($audit->metadata['changed_fields']['applicability_criteria']['new'])->toEqual(MANUFACTURING_51);
    });

    it('previews whether the criteria apply to the current organization', function (array $criteria, bool $applies): void {
        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements/criteria/preview', ['criteria' => $criteria])
            ->assertOk()
            ->assertExactJson(['applies' => $applies]);
    })->with([
        'gte 51: Yes' => [MANUFACTURING_51, true],
        'gte 251: No' => [MANUFACTURING_251, false],
    ]);
});

describe('exact messages', function (): void {
    it('rejects the case with its exact message and keeps the stored criteria', function (array $body, string $key, string $message): void {
        $requirement = ($this->makeRequirement)('Unchanged');

        $this->actingAs($this->staff)
            ->putJson(criteriaUrl($requirement), $body)
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errors) => ($errors[$key][0] ?? null) === $message);

        expect($requirement->fresh()->applicability_criteria)->toBe(['all' => []])
            ->and(AuditLog::query()->where('action', 'requirement.updated')->count())->toBe(0);
    })->with([
        'no conditions and "use criteria"' => [['use_criteria' => true, 'criteria' => ['all' => []]], 'criteria', 'Add at least one condition.'],
        'condition without field' => [['criteria' => ['all' => [['field' => '', 'op' => 'eq', 'value' => 'retail']]]], 'criteria.all.0.field', 'Select a field.'],
        'condition without operator' => [['criteria' => ['all' => [['field' => 'industry', 'op' => '', 'value' => ['retail']]]]], 'criteria.all.0.op', 'Select an operator.'],
        'condition without value' => [['criteria' => ['all' => [['field' => 'employees_count', 'op' => 'gte', 'value' => null]]]], 'criteria.all.0.value', 'Enter a value.'],
        'second condition without value' => [['criteria' => ['any' => [['field' => 'industry', 'op' => 'eq', 'value' => 'retail'], ['field' => 'state', 'op' => 'in', 'value' => []]]]], 'criteria.any.1.value', 'Enter a value.'],
        'operator not valid for the field' => [['criteria' => ['all' => [['field' => 'industry', 'op' => 'gte', 'value' => 50]]]], 'criteria.all.0.op', 'The operator gte cannot be used with industry.'],
        'between with business_activities' => [['criteria' => ['all' => [['field' => 'business_activities', 'op' => 'between', 'value' => ['21']]]]], 'criteria.all.0.op', 'The operator between cannot be used with business_activities.'],
        'criteria is not a group' => [['criteria' => [['field' => 'industry']]], 'criteria', 'The applicability criteria are not valid.'],
        'unknown catalog code' => [['criteria' => ['all' => [['field' => 'state', 'op' => 'eq', 'value' => '99']]]], 'criteria', 'The applicability criteria are not valid.'],
    ]);

    it('rejects malformed JSON with the exact message', function (): void {
        $requirement = ($this->makeRequirement)('Malformed');

        $this->actingAs($this->staff)
            ->call('PUT', criteriaUrl($requirement), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"use_criteria": true, "criteria": {"all": [')
            ->assertStatus(422)
            ->assertJsonPath('errors.criteria.0', 'The applicability criteria are not valid.');
    });

    it('returns 403 with the exact message for General Employees', function (): void {
        $requirement = ($this->makeRequirement)('Forbidden');

        $this->actingAs($this->employee)
            ->putJson(criteriaUrl($requirement), ['criteria' => MANUFACTURING_51])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to edit applicability criteria.');

        $this->actingAs($this->employee)
            ->postJson('/api/compliance-requirements/criteria/preview', ['criteria' => MANUFACTURING_51])
            ->assertForbidden()
            ->assertJsonPath('message', M::FORBIDDEN);
    });

    it('returns 404 for a requirement of another organization or that does not exist', function (): void {
        $requirement = ($this->makeRequirement)('Not yours');

        $this->actingAs($this->outsider)->putJson(criteriaUrl($requirement), ['criteria' => MANUFACTURING_51])->assertNotFound();
        $this->actingAs($this->staff)->putJson('/api/compliance-requirements/999999/criteria', ['criteria' => MANUFACTURING_51])
            ->assertNotFound()
            ->assertJsonPath('message', 'Compliance requirement not found.');
    });
});

it('uses the same messages in the React builder and in Laravel', function (): void {
    $frontend = file_get_contents(base_path('../frontend/src/features/criteria/messages.ts'));

    foreach ((new ReflectionClass(M::class))->getConstants() as $name => $text) {
        expect(str_contains($frontend, "'{$text}'"))->toBeTrue("{$name} is missing or different in messages.ts");
    }
})->skip(fn () => ! file_exists(base_path('../frontend/src/features/criteria/messages.ts')), 'The frontend folder is not available (for example inside the backend container).');
