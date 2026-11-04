<?php

use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Organization;
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
    $organization = $makeOrganization('Aceros del Guadiana, S.A. de C.V.');
    $other = $makeOrganization('Otra Empresa, S.A. de C.V.');

    $makeUser = fn (string $role, string $email, Organization $org) => User::query()->create([
        'organization_id' => $org->id, 'name' => ucwords(str_replace('_', ' ', $role)),
        'email' => $email, 'password' => 'password', 'role' => $role,
    ]);
    $this->staff = $makeUser('compliance_staff', 'staff@example.test', $organization);
    $this->admin = $makeUser('administrator', 'admin@example.test', $organization);
    $this->employee = $makeUser('general_employee', 'employee@example.test', $organization);
    $this->outsider = $makeUser('compliance_staff', 'outsider@example.test', $other);

    $this->requirement = ComplianceRequirement::query()->create([
        'organization_id' => $organization->id, 'name' => 'Psychosocial risk factors',
        'description' => 'Identify, analyze and prevent psychosocial risk factors at work.',
        'regulation' => 'NOM-035-STPS-2018', 'deadline' => '2026-12-31',
        'responsible_user_id' => $this->staff->id, 'status' => 'pending',
        'applicability_criteria' => ['all' => []], 'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->staff->id,
    ]);
    $this->url = "/api/compliance-requirements/{$this->requirement->id}/status";
});

function expectStatusUnchanged(ComplianceRequirement $requirement, string $status = 'pending'): void
{
    expect($requirement->fresh()->status)->toBe($status)
        ->and(AuditLog::query()->where('action', 'requirement.status_changed')->count())->toBe(0);
}

describe('error cases', function (): void {
    it('rejects a status outside the 4 allowed values with the exact message', function (mixed $status): void {
        $this->actingAs($this->staff)
            ->patchJson($this->url, ['status' => $status])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'Status must be one of: Compliant, Pending, At Risk, Overdue.');

        expectStatusUnchanged($this->requirement);
    })->with([
        'closed' => 'closed',
        'Closed (label)' => 'Closed',
        'Compliant (label instead of value)' => 'Compliant',
        'empty' => '',
        'null' => null,
        'number' => 1,
    ]);

    it('rejects a body without status with the exact message', function (): void {
        $this->actingAs($this->staff)
            ->patchJson($this->url, [])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', M::STATUS_INVALID);
    });

    it('rejects the same status as the current one with the exact message', function (): void {
        $this->actingAs($this->staff)
            ->patchJson($this->url, ['status' => 'pending'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'The requirement already has this status.');

        expectStatusUnchanged($this->requirement);
    });

    it('returns 404 with the exact message when the requirement does not exist', function (): void {
        $this->actingAs($this->staff)
            ->patchJson('/api/compliance-requirements/999999/status', ['status' => 'compliant'])
            ->assertNotFound()
            ->assertJsonPath('message', 'Compliance requirement not found.');
    });

    it('returns 404 with the exact message for a requirement of another organization', function (): void {
        $this->actingAs($this->outsider)
            ->patchJson($this->url, ['status' => 'compliant'])
            ->assertNotFound()
            ->assertJsonPath('message', M::NOT_FOUND);

        expectStatusUnchanged($this->requirement);
    });

    it('returns 403 with the exact message for Administrator and General Employee', function (string $role): void {
        $user = $role === 'administrator' ? $this->admin : $this->employee;

        $this->actingAs($user)
            ->patchJson($this->url, ['status' => 'compliant'])
            ->assertForbidden()
            ->assertJsonPath('message', 'You do not have permission to change compliance status.');

        expectStatusUnchanged($this->requirement);
    })->with(['administrator', 'general_employee']);

    it('checks permission before validating the status', function (): void {
        $this->actingAs($this->employee)
            ->patchJson($this->url, ['status' => 'closed'])
            ->assertForbidden()
            ->assertJsonPath('message', M::STATUS_FORBIDDEN);
    });

    it('requires authentication', function (): void {
        $this->patchJson($this->url, ['status' => 'compliant'])->assertUnauthorized();
    });
});

describe('successful change', function (): void {
    it('changes the status, returns the new badge data and the toast message', function (string $to, string $label): void {
        $this->actingAs($this->staff)
            ->patchJson($this->url, ['status' => $to])
            ->assertOk()
            ->assertJsonPath('message', "Status updated to {$label}.")
            ->assertJsonPath('data.status', ['code' => $to, 'label' => $label]);

        expect($this->requirement->fresh()->status)->toBe($to);
    })->with([
        ['compliant', 'Compliant'],
        ['at_risk', 'At Risk'],
        ['overdue', 'Overdue'],
    ]);

    it('writes requirement.status_changed with the correct from and to values', function (): void {
        $this->actingAs($this->staff)->patchJson($this->url, ['status' => 'at_risk'])->assertOk();
        $this->actingAs($this->staff)->patchJson($this->url, ['status' => 'compliant'])->assertOk();

        $records = AuditLog::query()->where('action', 'requirement.status_changed')->orderBy('id')->get();

        expect($records)->toHaveCount(2)
            ->and($records[0]->metadata)->toEqual(['from' => 'pending', 'to' => 'at_risk'])
            ->and($records[1]->metadata)->toEqual(['from' => 'at_risk', 'to' => 'compliant'])
            ->and($records[1]->user_id)->toBe($this->staff->id)
            ->and($records[1]->resource_type)->toBe('compliance_requirement')
            ->and($records[1]->resource_id)->toBe($this->requirement->id)
            ->and($records[1]->created_at)->not->toBeNull();
    });

    it('shows the new status in the detail endpoint', function (): void {
        $this->actingAs($this->staff)->patchJson($this->url, ['status' => 'overdue'])->assertOk();

        $this->actingAs($this->employee)
            ->getJson("/api/compliance-requirements/{$this->requirement->id}")
            ->assertOk()
            ->assertJsonPath('data.status', ['code' => 'overdue', 'label' => 'Overdue']);
    });

    it('returns the exact 404 message on the detail endpoint too', function (): void {
        $this->actingAs($this->staff)
            ->getJson('/api/compliance-requirements/999999')
            ->assertNotFound()
            ->assertJsonPath('message', M::NOT_FOUND);
    });
});
