<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Gate;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
    $this->organization = Organization::query()->create([
        'legal_name' => 'RBAC Test Organization',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 10,
        'business_activities' => ['51'],
    ]);
});

function rbacUser(Role $role): User
{
    return User::query()->create([
        'organization_id' => test()->organization->id,
        'name' => "{$role->value} user",
        'email' => "{$role->value}@example.test",
        'password' => 'password',
        'role' => $role,
        'is_active' => true,
    ]);
}

dataset('permission matrix', [
    ['manage-users', Role::Administrator, true], ['manage-users', Role::ComplianceStaff, false], ['manage-users', Role::GeneralEmployee, false],
    ['manage-organization', Role::Administrator, true], ['manage-organization', Role::ComplianceStaff, false], ['manage-organization', Role::GeneralEmployee, false],
    ['manage-requirements', Role::Administrator, true], ['manage-requirements', Role::ComplianceStaff, true], ['manage-requirements', Role::GeneralEmployee, false],
    ['change-compliance-status', Role::Administrator, false], ['change-compliance-status', Role::ComplianceStaff, true], ['change-compliance-status', Role::GeneralEmployee, false],
    ['upload-documents', Role::Administrator, true], ['upload-documents', Role::ComplianceStaff, true], ['upload-documents', Role::GeneralEmployee, false],
    ['view-documents', Role::Administrator, true], ['view-documents', Role::ComplianceStaff, true], ['view-documents', Role::GeneralEmployee, true],
    ['view-dashboard', Role::Administrator, true], ['view-dashboard', Role::ComplianceStaff, true], ['view-dashboard', Role::GeneralEmployee, false],
    ['use-ai-assistant', Role::Administrator, true], ['use-ai-assistant', Role::ComplianceStaff, true], ['use-ai-assistant', Role::GeneralEmployee, true],
]);

it('enforces every permissions matrix combination', function (string $ability, Role $role, bool $allowed): void {
    $user = rbacUser($role);

    expect(Gate::forUser($user)->allows($ability))->toBe($allowed);
})->with('permission matrix');

it('returns the global forbidden response and audits a denied API action', function (): void {
    $user = rbacUser(Role::GeneralEmployee);

    $this->actingAs($user)
        ->postJson('/api/documents', [
            'name' => 'Denied upload',
            'category' => 'policy',
            'access_level' => 'all_staff',
            'version' => '1.0',
            'file' => UploadedFile::fake()->create('policy.pdf', 10, 'application/pdf'),
        ])
        ->assertForbidden()
        ->assertExactJson(['message' => 'You do not have permission to upload documents.']);

    $this->assertDatabaseHas('audit_logs', [
        'user_id' => $user->id,
        'category' => 'security',
        'action' => 'access.denied',
    ]);

    $metadata = AuditLog::query()->where('action', 'access.denied')->sole()->metadata;
    expect($metadata)->toMatchArray([
        'route' => 'api/documents',
        'method' => 'POST',
        'user_role' => Role::GeneralEmployee->value,
    ]);
});
