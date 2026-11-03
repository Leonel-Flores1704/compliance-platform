<?php

use App\Events\OrganizationRegistered;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Support\OrganizationMessages as M;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

const EXISTING_LEGAL_NAME = 'Existing Organization, S.A. de C.V.';

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->existing = Organization::query()->create([
        'legal_name' => EXISTING_LEGAL_NAME, 'organization_type' => 'private_company',
        'industry' => 'technology', 'state' => '09', 'municipality' => 'Cuauhtemoc',
        'employees_count' => 10, 'business_activities' => ['51'],
    ]);

    $makeUser = fn (string $role) => User::query()->create([
        'organization_id' => $this->existing->id, 'name' => ucfirst($role),
        'email' => "{$role}@example.test", 'password' => 'password', 'role' => $role,
    ]);

    $this->admin = $makeUser('administrator');
    $this->staff = $makeUser('compliance_staff');
    $this->employee = $makeUser('general_employee');
});

function validOrganizationPayload(array $overrides = []): array
{
    return array_merge([
        'legal_name' => 'Aceros del Guadiana, S.A. de C.V.',
        'organization_type' => 'private_company',
        'industry' => 'manufacturing',
        'state' => '10',
        'municipality' => 'Durango',
        'employees_count' => 120,
        'business_activities' => ['31-33', '43'],
    ], $overrides);
}

describe('validation messages', function (): void {
    it('rejects the submission, saves nothing and returns the exact message', function (string $field, mixed $value, string $message): void {
        $before = Organization::query()->count();

        $this->actingAs($this->admin)
            ->postJson('/api/organizations', validOrganizationPayload([$field => $value]))
            ->assertStatus(422)
            ->assertJsonPath("errors.{$field}.0", $message);

        expect(Organization::query()->count())->toBe($before)
            ->and(AuditLog::query()->where('action', 'organization.created')->count())->toBe(0);
    })->with([
        'legal name empty' => ['legal_name', '', M::LEGAL_NAME_REQUIRED],
        'legal name only spaces' => ['legal_name', '   ', M::LEGAL_NAME_REQUIRED],
        'legal name shorter than 3' => ['legal_name', 'AB', M::LEGAL_NAME_LENGTH],
        'legal name longer than 150' => ['legal_name', str_repeat('A', 151), M::LEGAL_NAME_LENGTH],
        'legal name already registered' => ['legal_name', EXISTING_LEGAL_NAME, M::LEGAL_NAME_TAKEN],
        'legal name already registered (different case)' => ['legal_name', strtoupper(EXISTING_LEGAL_NAME), M::LEGAL_NAME_TAKEN],
        'organization type not selected' => ['organization_type', '', M::ORGANIZATION_TYPE_REQUIRED],
        'organization type not in catalog' => ['organization_type', 'government_agency', M::ORGANIZATION_TYPE_INVALID],
        'industry not selected' => ['industry', '', M::INDUSTRY_REQUIRED],
        'industry not in catalog' => ['industry', 'mining', M::INDUSTRY_INVALID],
        'state not selected' => ['state', '', M::STATE_REQUIRED],
        'state not in catalog' => ['state', '33', M::STATE_REQUIRED],
        'municipality empty' => ['municipality', '', M::MUNICIPALITY_REQUIRED],
        'number of employees empty' => ['employees_count', '', M::EMPLOYEES_REQUIRED],
        'number of employees not a whole number' => ['employees_count', 12.5, M::EMPLOYEES_INVALID],
        'number of employees as text' => ['employees_count', 'twelve', M::EMPLOYEES_INVALID],
        'number of employees zero' => ['employees_count', 0, M::EMPLOYEES_INVALID],
        'number of employees above 1,000,000' => ['employees_count', 1_000_001, M::EMPLOYEES_INVALID],
        'no business activity selected' => ['business_activities', [], M::ACTIVITIES_REQUIRED],
        'more than 10 business activities' => ['business_activities', ['11', '21', '22', '23', '31-33', '43', '46', '48-49', '51', '52', '53'], M::ACTIVITIES_MAX],
        'business activity not in catalog' => ['business_activities', ['99'], M::ACTIVITIES_INVALID],
        'municipality longer than 100' => ['municipality', str_repeat('M', 101), M::MUNICIPALITY_LENGTH],
    ]);

    it('accepts the limits of every range', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/organizations', validOrganizationPayload([
                'legal_name' => str_repeat('A', 150),
                'employees_count' => 1_000_000,
                'business_activities' => ['11', '21', '22', '23', '31-33', '43', '46', '48-49', '51', '52'],
            ]))
            ->assertCreated();

        $this->actingAs($this->admin)
            ->postJson('/api/organizations', validOrganizationPayload(['legal_name' => 'ABC', 'employees_count' => 1, 'business_activities' => ['54']]))
            ->assertCreated();
    });

    it('returns every invalid field at once and saves nothing', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/organizations', [])
            ->assertStatus(422)
            ->assertJsonPath('errors.legal_name.0', M::LEGAL_NAME_REQUIRED)
            ->assertJsonPath('errors.organization_type.0', M::ORGANIZATION_TYPE_REQUIRED)
            ->assertJsonPath('errors.industry.0', M::INDUSTRY_REQUIRED)
            ->assertJsonPath('errors.state.0', M::STATE_REQUIRED)
            ->assertJsonPath('errors.municipality.0', M::MUNICIPALITY_REQUIRED)
            ->assertJsonPath('errors.employees_count.0', M::EMPLOYEES_REQUIRED)
            ->assertJsonPath('errors.business_activities.0', M::ACTIVITIES_REQUIRED);

        expect(Organization::query()->count())->toBe(1);
    });
});

describe('authorization', function (): void {
    it('returns 403 with the exact message for non-administrators', function (string $role): void {
        $user = $role === 'compliance_staff' ? $this->staff : $this->employee;

        $this->actingAs($user)
            ->postJson('/api/organizations', validOrganizationPayload())
            ->assertForbidden()
            ->assertJsonPath('message', M::FORBIDDEN);

        expect(Organization::query()->count())->toBe(1);
    })->with(['compliance_staff', 'general_employee']);

    it('checks permission before validation', function (): void {
        $this->actingAs($this->employee)
            ->postJson('/api/organizations', [])
            ->assertForbidden()
            ->assertJsonPath('message', M::FORBIDDEN);
    });

    it('requires authentication', function (): void {
        $this->postJson('/api/organizations', validOrganizationPayload())->assertUnauthorized();
        $this->getJson('/api/catalogs/organization')->assertUnauthorized();
    });
});

describe('successful registration', function (): void {
    it('stores the 6 characteristics and returns them', function (): void {
        Event::fake([OrganizationRegistered::class]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/organizations', validOrganizationPayload(['legal_name' => '  Aceros del Guadiana, S.A. de C.V.  ']))
            ->assertCreated()
            ->assertJsonPath('message', M::CREATED)
            ->assertJsonPath('data.legal_name', 'Aceros del Guadiana, S.A. de C.V.')
            ->assertJsonPath('data.organization_type', ['code' => 'private_company', 'name' => 'Private company'])
            ->assertJsonPath('data.industry', ['code' => 'manufacturing', 'name' => 'Manufacturing'])
            ->assertJsonPath('data.state', ['code' => '10', 'name' => 'Durango'])
            ->assertJsonPath('data.municipality', 'Durango')
            ->assertJsonPath('data.employees_count', 120)
            ->assertJsonPath('data.business_activities', [
                ['code' => '31-33', 'name' => 'Manufacturing'],
                ['code' => '43', 'name' => 'Wholesale trade'],
            ]);

        $organization = Organization::query()->findOrFail($response->json('data.id'));

        expect($organization->only(['legal_name', 'organization_type', 'industry', 'state', 'municipality', 'employees_count', 'business_activities', 'is_active']))
            ->toBe([
                'legal_name' => 'Aceros del Guadiana, S.A. de C.V.',
                'organization_type' => 'private_company',
                'industry' => 'manufacturing',
                'state' => '10',
                'municipality' => 'Durango',
                'employees_count' => 120,
                'business_activities' => ['31-33', '43'],
                'is_active' => true,
            ]);

        Event::assertDispatched(OrganizationRegistered::class, fn ($event) => $event->organization->is($organization));
    });

    it('shows the stored values on the detail endpoint', function (): void {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/organizations', validOrganizationPayload())
            ->json('data.id');

        $this->actingAs($this->employee)
            ->getJson("/api/organizations/{$id}")
            ->assertOk()
            ->assertJsonPath('data.legal_name', 'Aceros del Guadiana, S.A. de C.V.')
            ->assertJsonPath('data.state.name', 'Durango')
            ->assertJsonPath('data.employees_count', 120)
            ->assertJsonCount(2, 'data.business_activities');
    });

    it('writes an organization.created audit record with the administrator id', function (): void {
        $id = $this->actingAs($this->admin)
            ->postJson('/api/organizations', validOrganizationPayload())
            ->json('data.id');

        $audit = AuditLog::query()->where('action', 'organization.created')->sole();

        expect($audit->user_id)->toBe($this->admin->id)
            ->and($audit->category)->toBe('functional')
            ->and($audit->resource_type)->toBe('organization')
            ->and($audit->resource_id)->toBe($id);
    });

    it('merges duplicated business activities', function (): void {
        $this->actingAs($this->admin)
            ->postJson('/api/organizations', validOrganizationPayload(['business_activities' => ['43', '43']]))
            ->assertCreated()
            ->assertJsonCount(1, 'data.business_activities');
    });
});

it('returns the 4 organization catalogs', function (): void {
    $this->actingAs($this->employee)
        ->getJson('/api/catalogs/organization')
        ->assertOk()
        ->assertJsonCount(4, 'organization_types')
        ->assertJsonCount(10, 'industries')
        ->assertJsonCount(32, 'states')
        ->assertJsonCount(20, 'business_activities')
        ->assertJsonPath('states.9', ['code' => '10', 'name' => 'Durango'])
        ->assertJsonPath('industries.9', ['code' => 'other', 'name' => 'Other']);
});

it('uses the same messages in the React form and in Laravel', function (): void {
    $frontend = file_get_contents(base_path('../frontend/src/features/organizations/messages.ts'));
    $messages = (new ReflectionClass(M::class))->getConstants();

    foreach ($messages as $name => $text) {
        expect(str_contains($frontend, "'{$text}'"))->toBeTrue("{$name} is missing or different in messages.ts");
    }
})->skip(fn () => ! file_exists(base_path('../frontend/src/features/organizations/messages.ts')), 'The frontend folder is not available (for example inside the backend container).');
