<?php

use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Organization;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Support\RequirementMessages as M;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $makeOrganization = fn (string $name) => Organization::query()->create([
        'legal_name' => $name, 'organization_type' => 'private_company', 'industry' => 'manufacturing',
        'state' => '10', 'municipality' => 'Durango', 'employees_count' => 120, 'business_activities' => ['31-33'],
    ]);
    $this->organization = $makeOrganization('Aceros del Guadiana, S.A. de C.V.');
    $this->otherOrganization = $makeOrganization('Otra Empresa, S.A. de C.V.');

    $makeUser = fn (string $role, string $email, ?Organization $organization = null, bool $active = true) => User::query()->create([
        'organization_id' => ($organization ?? $this->organization)->id, 'name' => ucwords(str_replace('_', ' ', $role)),
        'email' => $email, 'password' => 'password', 'role' => $role, 'is_active' => $active,
    ]);

    $this->admin = $makeUser('administrator', 'admin@example.test');
    $this->staff = $makeUser('compliance_staff', 'staff@example.test');
    $this->employee = $makeUser('general_employee', 'employee@example.test');
    $this->inactive = $makeUser('compliance_staff', 'inactive@example.test', active: false);
    $this->outsider = $makeUser('compliance_staff', 'outsider@example.test', $this->otherOrganization);
});

function validRequirementPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'Psychosocial risk factors',
        'description' => 'Identify, analyze and prevent psychosocial risk factors at work.',
        'regulation' => 'NOM-035-STPS-2018',
        'deadline' => '2026-12-31',
        'required_documents' => [
            ['name' => 'Psychosocial risk policy', 'category' => 'policy'],
            ['name' => 'Risk evaluation report', 'category' => 'report'],
        ],
        'responsible_user_id' => null,
        'status' => 'pending',
    ], $overrides);
}

function expectNothingStored(): void
{
    expect(ComplianceRequirement::query()->count())->toBe(0)
        ->and(RequiredDocument::query()->count())->toBe(0)
        ->and(AuditLog::query()->where('action', 'requirement.created')->count())->toBe(0);
}

describe('validation messages', function (): void {
    it('rejects the case, stores nothing and returns the exact message', function (array $overrides, string $errorKey, string $message): void {
        // Datasets cannot reference users created in beforeEach, so they use placeholders.
        $overrides = array_map(fn ($value) => match ($value) {
            'OUTSIDER' => $this->outsider->id,
            'INACTIVE' => $this->inactive->id,
            default => $value,
        }, $overrides);
        $payload = array_merge(validRequirementPayload(['responsible_user_id' => $this->staff->id]), $overrides);
        if ($payload['required_documents'] === 'MISSING') {
            unset($payload['required_documents']);
        }

        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', $payload)
            ->assertStatus(422)
            ->assertJsonPath('errors', fn (array $errors) => ($errors[$errorKey][0] ?? null) === $message);

        expectNothingStored();
    })->with([
        'name empty' => [['name' => ''], 'name', M::NAME_REQUIRED],
        'name shorter than 3' => [['name' => 'AB'], 'name', M::NAME_LENGTH],
        'name longer than 150' => [['name' => str_repeat('N', 151)], 'name', M::NAME_LENGTH],
        'description empty' => [['description' => ''], 'description', M::DESCRIPTION_REQUIRED],
        'description shorter than 10' => [['description' => 'Too short'], 'description', M::DESCRIPTION_LENGTH],
        'description longer than 2000' => [['description' => str_repeat('D', 2001)], 'description', M::DESCRIPTION_LENGTH],
        'regulation empty' => [['regulation' => ''], 'regulation', M::REGULATION_REQUIRED],
        'regulation shorter than 2' => [['regulation' => 'N'], 'regulation', M::REGULATION_LENGTH],
        'regulation longer than 150' => [['regulation' => str_repeat('R', 151)], 'regulation', M::REGULATION_LENGTH],
        'deadline empty' => [['deadline' => ''], 'deadline', M::DEADLINE_REQUIRED],
        'deadline in DD/MM/YYYY format' => [['deadline' => '31/12/2026'], 'deadline', M::DEADLINE_INVALID],
        'deadline that does not exist' => [['deadline' => '2026-02-30'], 'deadline', M::DEADLINE_INVALID],
        'deadline as text' => [['deadline' => 'tomorrow'], 'deadline', M::DEADLINE_INVALID],
        'no required documents (empty list)' => [['required_documents' => []], 'required_documents', M::DOCUMENTS_REQUIRED],
        'no required documents (field missing)' => [['required_documents' => 'MISSING'], 'required_documents', M::DOCUMENTS_REQUIRED],
        'required document without name' => [['required_documents' => [['name' => '', 'category' => 'policy']]], 'required_documents.0.name', M::DOCUMENT_INCOMPLETE],
        'required document without category' => [['required_documents' => [['name' => 'Policy', 'category' => '']]], 'required_documents.0.category', M::DOCUMENT_INCOMPLETE],
        'second required document without category' => [['required_documents' => [['name' => 'Policy', 'category' => 'policy'], ['name' => 'Report']]], 'required_documents.1.category', M::DOCUMENT_INCOMPLETE],
        'required document with unknown category' => [['required_documents' => [['name' => 'Policy', 'category' => 'invoice']]], 'required_documents.0.category', M::DOCUMENT_INCOMPLETE],
        'required document name longer than 150' => [['required_documents' => [['name' => str_repeat('P', 151), 'category' => 'policy']]], 'required_documents.0.name', M::DOCUMENT_NAME_LENGTH],
        'responsible user empty' => [['responsible_user_id' => ''], 'responsible_user_id', M::RESPONSIBLE_REQUIRED],
        'responsible user does not exist' => [['responsible_user_id' => 999999], 'responsible_user_id', M::RESPONSIBLE_INVALID],
        'responsible user from another organization' => [['responsible_user_id' => 'OUTSIDER'], 'responsible_user_id', M::RESPONSIBLE_INVALID],
        'responsible user inactive' => [['responsible_user_id' => 'INACTIVE'], 'responsible_user_id', M::RESPONSIBLE_INVALID],
        'status Closed' => [['status' => 'Closed'], 'status', M::STATUS_INVALID],
        'status empty' => [['status' => ''], 'status', M::STATUS_INVALID],
    ]);

    it('rejects status "Closed" sent directly to the API with the exact message', function (): void {
        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->staff->id, 'status' => 'Closed']))
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Status must be one of: Compliant, Pending, At Risk, Overdue.');

        expectNothingStored();
    });

    it('returns every invalid field at once and stores nothing', function (): void {
        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', ['status' => 'closed'])
            ->assertStatus(422)
            ->assertJsonPath('errors.name.0', M::NAME_REQUIRED)
            ->assertJsonPath('errors.description.0', M::DESCRIPTION_REQUIRED)
            ->assertJsonPath('errors.regulation.0', M::REGULATION_REQUIRED)
            ->assertJsonPath('errors.deadline.0', M::DEADLINE_REQUIRED)
            ->assertJsonPath('errors.required_documents.0', M::DOCUMENTS_REQUIRED)
            ->assertJsonPath('errors.responsible_user_id.0', M::RESPONSIBLE_REQUIRED)
            ->assertJsonPath('errors.status.0', M::STATUS_INVALID);

        expectNothingStored();
    });
});

describe('authorization', function (): void {
    it('returns 403 with the exact message for General Employees', function (): void {
        $this->actingAs($this->employee)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->staff->id]))
            ->assertForbidden()
            ->assertJsonPath('message', M::FORBIDDEN);

        expectNothingStored();
    });

    it('allows Administrators (ADR-005, confirmed in CMP-02)', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->staff->id]))
            ->assertCreated();
    });

    it('requires authentication', function (): void {
        $this->postJson('/api/compliance-requirements', validRequirementPayload())->assertUnauthorized();
        $this->getJson('/api/users/options')->assertUnauthorized();
    });

    it('does not show a requirement of another organization', function (): void {
        $id = $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->staff->id]))
            ->json('data.id');

        $this->actingAs($this->outsider)->getJson("/api/compliance-requirements/{$id}")->assertForbidden();
    });
});

describe('successful creation', function (): void {
    it('stores the 7 fields and the required documents and returns them', function (): void {
        $response = $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->staff->id, 'status' => 'at_risk']))
            ->assertCreated()
            ->assertJsonPath('message', M::CREATED)
            ->assertJsonPath('data.name', 'Psychosocial risk factors')
            ->assertJsonPath('data.description', 'Identify, analyze and prevent psychosocial risk factors at work.')
            ->assertJsonPath('data.regulation', 'NOM-035-STPS-2018')
            ->assertJsonPath('data.deadline', '2026-12-31')
            ->assertJsonPath('data.status', ['code' => 'at_risk', 'label' => 'At Risk'])
            ->assertJsonPath('data.responsible_user', ['id' => $this->staff->id, 'name' => 'Compliance Staff'])
            ->assertJsonPath('data.required_documents.0.name', 'Psychosocial risk policy')
            ->assertJsonPath('data.required_documents.0.category', ['code' => 'policy', 'name' => 'Policy'])
            ->assertJsonPath('data.required_documents.1.category.code', 'report');

        $requirement = ComplianceRequirement::query()->findOrFail($response->json('data.id'));

        expect($requirement->organization_id)->toBe($this->organization->id)
            ->and($requirement->created_by)->toBe($this->staff->id)
            ->and($requirement->deadline->toDateString())->toBe('2026-12-31')
            ->and($requirement->status)->toBe('at_risk')
            ->and($requirement->notification_periods)->toBe([30, 15, 7, 1])
            ->and($requirement->requiredDocuments()->count())->toBe(2);
    });

    it('uses Pending as the initial status when none is sent', function (): void {
        $payload = validRequirementPayload(['responsible_user_id' => $this->staff->id]);
        unset($payload['status']);

        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', $payload)
            ->assertCreated()
            ->assertJsonPath('data.status', ['code' => 'pending', 'label' => 'Pending']);
    });

    it('accepts the 4 allowed statuses', function (string $status): void {
        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->staff->id, 'status' => $status]))
            ->assertCreated()
            ->assertJsonPath('data.status.code', $status);
    })->with(['compliant', 'pending', 'at_risk', 'overdue']);

    it('accepts the limits of every range', function (): void {
        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload([
                'responsible_user_id' => $this->staff->id,
                'name' => 'ABC', 'description' => str_repeat('D', 10), 'regulation' => 'NO',
                'required_documents' => [['name' => 'P', 'category' => 'other']],
            ]))
            ->assertCreated();

        $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload([
                'responsible_user_id' => $this->staff->id,
                'name' => str_repeat('N', 150), 'description' => str_repeat('D', 2000), 'regulation' => str_repeat('R', 150),
            ]))
            ->assertCreated();
    });

    it('shows the stored fields on the detail endpoint', function (): void {
        $id = $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->admin->id]))
            ->json('data.id');

        $this->actingAs($this->admin)
            ->getJson("/api/compliance-requirements/{$id}")
            ->assertOk()
            ->assertJsonPath('data.regulation', 'NOM-035-STPS-2018')
            ->assertJsonPath('data.responsible_user.name', 'Administrator')
            ->assertJsonPath('data.status.label', 'Pending')
            ->assertJsonCount(2, 'data.required_documents');
    });

    it('writes a requirement.created audit record with user, requirement id and timestamp', function (): void {
        $id = $this->actingAs($this->staff)
            ->postJson('/api/compliance-requirements', validRequirementPayload(['responsible_user_id' => $this->staff->id]))
            ->json('data.id');

        $audit = AuditLog::query()->where('action', 'requirement.created')->sole();

        expect($audit->user_id)->toBe($this->staff->id)
            ->and($audit->resource_type)->toBe('compliance_requirement')
            ->and($audit->resource_id)->toBe($id)
            ->and($audit->created_at)->not->toBeNull()
            ->and($audit->category)->toBe('functional');
    });
});

it('lists active users of the organization with id and name only', function (): void {
    $this->actingAs($this->staff)
        ->getJson('/api/users/options')
        ->assertOk()
        ->assertExactJson(['data' => [
            ['id' => $this->admin->id, 'name' => 'Administrator'],
            ['id' => $this->staff->id, 'name' => 'Compliance Staff'],
            ['id' => $this->employee->id, 'name' => 'General Employee'],
        ]]);
});

it('returns the document categories', function (): void {
    $this->actingAs($this->staff)
        ->getJson('/api/catalogs/document-categories')
        ->assertOk()
        ->assertJsonCount(7, 'document_categories')
        ->assertJsonPath('document_categories.6', ['code' => 'other', 'name' => 'Other']);
});

it('uses the same messages in the React form and in Laravel', function (): void {
    $frontend = file_get_contents(base_path('../frontend/src/features/requirements/messages.ts'));

    foreach ((new ReflectionClass(M::class))->getConstants() as $name => $text) {
        if (is_string($text)) {
            expect(str_contains($frontend, "'{$text}'"))->toBeTrue("{$name} is missing or different in messages.ts");
        }
    }
})->skip(fn () => ! file_exists(base_path('../frontend/src/features/requirements/messages.ts')), 'The frontend folder is not available (for example inside the backend container).');
