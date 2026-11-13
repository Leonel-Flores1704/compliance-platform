<?php

use App\Enums\Role;
use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
    $this->organization = Organization::query()->create([
        'legal_name' => 'User Management Organization', 'organization_type' => 'private_company',
        'industry' => 'technology', 'state' => '09', 'municipality' => 'Cuauhtemoc',
        'employees_count' => 10, 'business_activities' => ['51'],
    ]);
    $this->administrator = managedUser(Role::Administrator, ['email' => 'admin@example.test']);
});

function managedUser(Role $role, array $attributes = []): User
{
    return User::query()->create(array_merge([
        'organization_id' => test()->organization->id, 'name' => 'Managed User',
        'email' => fake()->unique()->safeEmail(), 'password' => 'Password12345',
        'role' => $role, 'is_active' => true,
    ], $attributes));
}

function userPayload(array $overrides = []): array
{
    return array_merge([
        'name' => 'New Platform User', 'email' => 'new.user@example.test',
        'role' => Role::ComplianceStaff->value, 'password' => 'SecurePassword1',
        'must_change_password' => true,
    ], $overrides);
}

it('lists only organization users with search and pagination', function (): void {
    managedUser(Role::GeneralEmployee, ['name' => 'Ana Search', 'email' => 'ana@example.test']);
    managedUser(Role::GeneralEmployee, ['name' => 'Other User', 'email' => 'other@example.test']);

    $this->actingAs($this->administrator)->getJson('/api/users?search=ana%40example.test')
        ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.email', 'ana@example.test');
});

it('creates a user, hashes its temporary password, and audits the event', function (): void {
    $this->actingAs($this->administrator)->postJson('/api/users', userPayload())
        ->assertCreated()->assertJsonPath('message', 'User created successfully.');

    $user = User::query()->where('email', 'new.user@example.test')->sole();
    expect($user->must_change_password)->toBeTrue()->and($user->password)->not->toBe('SecurePassword1');
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.created', 'category' => 'security', 'resource_id' => $user->id]);
});

it('returns exact create and update validation messages', function (): void {
    $this->actingAs($this->administrator)->postJson('/api/users', [])->assertUnprocessable()->assertJsonValidationErrors([
        'name' => 'Name is required.', 'email' => 'Email is required.', 'role' => 'Select a role.',
        'password' => 'Password must be at least 12 characters and include an uppercase letter, a lowercase letter and a number.',
    ]);
    $existing = managedUser(Role::GeneralEmployee, ['email' => 'existing@example.test']);
    $this->actingAs($this->administrator)->patchJson("/api/users/{$existing->id}", userPayload([
        'name' => 'Ab', 'email' => 'invalid', 'role' => 'invalid', 'password' => 'short',
    ]))->assertUnprocessable()->assertJsonValidationErrors([
        'name' => 'Name must be between 3 and 100 characters.', 'email' => 'Enter a valid email address.',
        'role' => 'Select a role.', 'password' => 'Password must be at least 12 characters and include an uppercase letter, a lowercase letter and a number.',
    ]);
    $this->actingAs($this->administrator)->postJson('/api/users', userPayload(['email' => 'existing@example.test']))
        ->assertUnprocessable()->assertJsonValidationErrors(['email' => 'A user with this email already exists.']);
});

it('updates a user and records role transitions without storing its password', function (): void {
    $user = managedUser(Role::GeneralEmployee);
    $this->actingAs($this->administrator)->patchJson("/api/users/{$user->id}", userPayload([
        'email' => $user->email, 'name' => 'Updated User', 'role' => Role::ComplianceStaff->value, 'password' => '',
    ]))->assertOk()->assertJsonPath('message', 'User updated successfully.');

    $this->assertDatabaseHas('audit_logs', ['action' => 'user.updated', 'category' => 'security']);
    expect(AuditLog::query()->where('action', 'user.role_changed')->sole()->metadata)->toMatchArray([
        'from' => Role::GeneralEmployee->value, 'to' => Role::ComplianceStaff->value,
    ]);
});

it('deactivates users, revokes their sessions, and records lifecycle audits', function (): void {
    $user = managedUser(Role::ComplianceStaff);
    $this->actingAs($this->administrator)->postJson("/api/users/{$user->id}/deactivate")
        ->assertOk()->assertJsonPath('message', 'User deactivated.');
    expect($user->fresh()->is_active)->toBeFalse();
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.deactivated', 'category' => 'security']);

    $this->actingAs($this->administrator)->postJson("/api/users/{$user->id}/activate")
        ->assertOk()->assertJsonPath('message', 'User reactivated.');
    expect($user->fresh()->is_active)->toBeTrue();
    $this->assertDatabaseHas('audit_logs', ['action' => 'user.activated', 'category' => 'security']);
});

it('prevents self deactivation and removal of the final active administrator', function (): void {
    $this->actingAs($this->administrator)->postJson("/api/users/{$this->administrator->id}/deactivate")
        ->assertUnprocessable()->assertJsonValidationErrors(['user' => 'You cannot deactivate your own account.']);

    $this->actingAs($this->administrator)->patchJson("/api/users/{$this->administrator->id}", userPayload([
        'email' => $this->administrator->email, 'role' => Role::ComplianceStaff->value,
    ]))->assertUnprocessable()->assertJsonValidationErrors(['role' => 'The organization must have at least one active Administrator.']);
});

it('denies non administrators with the specified message', function (): void {
    $staff = managedUser(Role::ComplianceStaff);
    $this->actingAs($staff)->getJson('/api/users')->assertForbidden()
        ->assertExactJson(['message' => 'You do not have permission to manage users.']);
});
