<?php

use App\Exceptions\AuditLogException;
use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\RequiredDocument;
use App\Models\User;
use App\Services\AuditLogger;
use Carbon\Carbon;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);

    $this->organization = Organization::query()->create([
        'legal_name' => 'Audit Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 50,
        'business_activities' => ['51'],
    ]);

    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Audit Admin',
        'email' => 'audit_admin@example.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);
});

// Acceptance Criteria 1: Pest tests assert one audit record per action listed, with user_id, action, resource_type, resource_id, and created_at filled.

it('records an audit log for organization.created', function (): void {
    $this->actingAs($this->user);

    $org = Organization::query()->create([
        'legal_name' => 'New Org for Audit',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 15,
        'business_activities' => ['51'],
    ]);

    $log = AuditLog::where('action', 'organization.created')
        ->where('resource_id', $org->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('organization.created')
        ->and($log->resource_type)->toBe('organization')
        ->and($log->resource_id)->toBe($org->id)
        ->and($log->created_at)->not->toBeNull();
});

it('records an audit log for organization.updated', function (): void {
    $this->actingAs($this->user);

    $this->organization->update([
        'legal_name' => 'Updated Legal Name',
    ]);

    $log = AuditLog::where('action', 'organization.updated')
        ->where('resource_id', $this->organization->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('organization.updated')
        ->and($log->resource_type)->toBe('organization')
        ->and($log->resource_id)->toBe($this->organization->id)
        ->and($log->created_at)->not->toBeNull()
        ->and($log->metadata['changed_fields']['legal_name']['old'])->toBe('Audit Test Org')
        ->and($log->metadata['changed_fields']['legal_name']['new'])->toBe('Updated Legal Name');
});

it('records an audit log for document.uploaded', function (): void {
    $this->actingAs($this->user);

    $doc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Security Policy 2026',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $log = AuditLog::where('action', 'document.uploaded')
        ->where('resource_id', $doc->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('document.uploaded')
        ->and($log->resource_type)->toBe('document')
        ->and($log->resource_id)->toBe($doc->id)
        ->and($log->created_at)->not->toBeNull();
});

it('records an audit log for document_version.created', function (): void {
    $this->actingAs($this->user);

    $doc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Versioned Document',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $version = DocumentVersion::query()->create([
        'document_id' => $doc->id,
        'version_label' => '1.0.0',
        'storage_path' => 'documents/test.bin',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => str_repeat('b', 64),
        'status' => 'queued',
        'is_current' => false,
        'uploaded_by' => $this->user->id,
    ]);

    $log = AuditLog::where('action', 'document_version.created')
        ->where('resource_id', $version->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('document_version.created')
        ->and($log->resource_type)->toBe('document_version')
        ->and($log->resource_id)->toBe($version->id)
        ->and($log->created_at)->not->toBeNull();
});

it('records an audit log for document_version.set_current', function (): void {
    $this->actingAs($this->user);

    $doc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Current Version Test',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $version = DocumentVersion::query()->create([
        'document_id' => $doc->id,
        'version_label' => '1.0.0',
        'storage_path' => 'documents/test.bin',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => str_repeat('c', 64),
        'status' => 'queued',
        'is_current' => true,
        'uploaded_by' => $this->user->id,
    ]);

    $log = AuditLog::where('action', 'document_version.set_current')
        ->where('resource_id', $version->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('document_version.set_current')
        ->and($log->resource_type)->toBe('document_version')
        ->and($log->resource_id)->toBe($version->id)
        ->and($log->created_at)->not->toBeNull();
});

it('records an audit log for document_version.processing_failed', function (): void {
    $this->actingAs($this->user);

    $doc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Failed Processing Doc',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $version = DocumentVersion::query()->create([
        'document_id' => $doc->id,
        'version_label' => '1.0.0',
        'storage_path' => 'documents/test.bin',
        'original_filename' => 'test.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 1024,
        'sha256' => str_repeat('d', 64),
        'status' => 'queued',
        'is_current' => false,
        'uploaded_by' => $this->user->id,
    ]);

    $version->update([
        'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
        'failure_reason' => 'No text could be extracted from this document.',
    ]);

    $log = AuditLog::where('action', 'document_version.processing_failed')
        ->where('resource_id', $version->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('document_version.processing_failed')
        ->and($log->resource_type)->toBe('document_version')
        ->and($log->resource_id)->toBe($version->id)
        ->and($log->created_at)->not->toBeNull();
});

it('records an audit log for requirement.created', function (): void {
    $this->actingAs($this->user);

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Annual Tax Compliance',
        'description' => 'Submit audited annual financial report',
        'regulation' => 'Tax Code 2026',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    $log = AuditLog::where('action', 'requirement.created')
        ->where('resource_id', $req->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('requirement.created')
        ->and($log->resource_type)->toBe('requirement')
        ->and($log->resource_id)->toBe($req->id)
        ->and($log->created_at)->not->toBeNull();
});

it('records an audit log for requirement.updated with changed fields metadata', function (): void {
    $this->actingAs($this->user);

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Original Requirement Name',
        'description' => 'Original description',
        'regulation' => 'Tax Code 2026',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    $req->update([
        'name' => 'Revised Requirement Name',
        'description' => 'Revised description',
    ]);

    $log = AuditLog::where('action', 'requirement.updated')
        ->where('resource_id', $req->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('requirement.updated')
        ->and($log->resource_type)->toBe('requirement')
        ->and($log->resource_id)->toBe($req->id)
        ->and($log->created_at)->not->toBeNull()
        ->and($log->metadata['changed_fields']['name']['old'])->toBe('Original Requirement Name')
        ->and($log->metadata['changed_fields']['name']['new'])->toBe('Revised Requirement Name');
});

// Acceptance Criteria 2: Changing a status from Pending to Compliant creates requirement.status_changed with metadata {"from": "pending", "to": "compliant"}.

it('creates requirement.status_changed with metadata {"from": "pending", "to": "compliant"} when status changes from Pending to Compliant', function (): void {
    $this->actingAs($this->user);

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Status Change Test Requirement',
        'description' => 'Testing status transition',
        'regulation' => 'ISO 27001',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    $req->update(['status' => 'compliant']);

    $log = AuditLog::where('action', 'requirement.status_changed')
        ->where('resource_id', $req->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->action)->toBe('requirement.status_changed')
        ->and($log->resource_type)->toBe('requirement')
        ->and($log->resource_id)->toBe($req->id)
        ->and($log->created_at)->not->toBeNull()
        ->and($log->metadata)->toEqual(['from' => 'pending', 'to' => 'compliant']);
});

it('records an audit log for requirement.document_linked', function (): void {
    $this->actingAs($this->user);

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Document Link Test Requirement',
        'description' => 'Testing document link',
        'regulation' => 'ISO 27001',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    $doc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Audit Report',
        'category' => 'report',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $requiredDoc = RequiredDocument::query()->create([
        'compliance_requirement_id' => $req->id,
        'name' => 'Report Attachment',
        'category' => 'report',
        'document_id' => null,
    ]);

    $requiredDoc->update(['document_id' => $doc->id]);

    $log = AuditLog::where('action', 'requirement.document_linked')
        ->where('resource_id', $req->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('requirement.document_linked')
        ->and($log->resource_type)->toBe('requirement')
        ->and($log->resource_id)->toBe($req->id)
        ->and($log->created_at)->not->toBeNull();
});

it('records an audit log for requirement.document_unlinked', function (): void {
    $this->actingAs($this->user);

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Document Unlink Test Requirement',
        'description' => 'Testing document unlink',
        'regulation' => 'ISO 27001',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    $doc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Audit Report to Unlink',
        'category' => 'report',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $requiredDoc = RequiredDocument::query()->create([
        'compliance_requirement_id' => $req->id,
        'name' => 'Report Attachment',
        'category' => 'report',
        'document_id' => $doc->id,
    ]);

    $requiredDoc->update(['document_id' => null]);

    $log = AuditLog::where('action', 'requirement.document_unlinked')
        ->where('resource_id', $req->id)
        ->first();

    expect($log)->not->toBeNull()
        ->and($log->user_id)->toBe($this->user->id)
        ->and($log->category)->toBe('functional')
        ->and($log->action)->toBe('requirement.document_unlinked')
        ->and($log->resource_type)->toBe('requirement')
        ->and($log->resource_id)->toBe($req->id)
        ->and($log->created_at)->not->toBeNull();
});

// Acceptance Criteria 3: Simulated audit failure (mocked) leaves the requirement unchanged and returns the exact message above.

it('leaves requirement unchanged and returns HTTP 500 with exact message on simulated audit failure via API', function (): void {
    $this->actingAs($this->user);

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Rollback Test Requirement',
        'description' => 'Must not change on audit failure',
        'regulation' => 'GDPR',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    // Mock AuditLogger to force a failure
    AuditLogger::shouldReceive('log')
        ->andThrow(new Exception('Simulated database audit failure'));

    $response = $this->patchJson("/api/requirements/{$req->id}", [
        'status' => 'compliant',
    ]);

    $response->assertStatus(500)
        ->assertExactJson([
            'message' => 'The action could not be completed. Please try again.',
        ]);

    // Verify DB rollback
    expect($req->fresh()->status)->toBe('pending');
});

it('leaves requirement unchanged and rolls back database transaction on simulated audit failure via direct model update', function (): void {
    $this->actingAs($this->user);

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Direct Model Rollback Test',
        'description' => 'Must not change on direct model audit failure',
        'regulation' => 'SOX',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    // Mock AuditLogger to force a failure
    AuditLogger::shouldReceive('log')
        ->andThrow(new Exception('Simulated audit failure'));

    try {
        $req->update(['status' => 'compliant']);
        $this->fail('Expected an exception on audit failure.');
    } catch (Throwable $e) {
        expect($e)->toBeInstanceOf(AuditLogException::class)
            ->and($e->getMessage())->toBe('The action could not be completed. Please try again.');
    }

    // Verify the requirement in the database was rolled back and is still pending
    expect($req->fresh()->status)->toBe('pending');
});

// Additional tests for timestamps in UTC and displayed in America/Mexico_City
it('stores timestamps in UTC and displays them in America/Mexico_City', function (): void {
    $this->actingAs($this->user);

    // Freeze time to a specific UTC instant
    Carbon::setTestNow(Carbon::parse('2026-11-09 03:00:00', 'UTC'));

    $req = ComplianceRequirement::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Timezone Requirement',
        'description' => 'Test timezone handling',
        'regulation' => 'NOM-151',
        'deadline' => '2026-12-31',
        'responsible_user_id' => $this->user->id,
        'status' => 'pending',
        'notification_periods' => [30, 15, 7, 1],
        'created_by' => $this->user->id,
    ]);

    $log = AuditLog::where('action', 'requirement.created')
        ->where('resource_id', $req->id)
        ->first();

    // Verify stored timestamp is in UTC
    expect($log->created_at->toDateTimeString())->toBe('2026-11-09 03:00:00');

    // Verify displayed timestamp is in America/Mexico_City (UTC-6)
    expect($log->displayedCreatedAt())->toBe('2026-11-08 21:00:00')
        ->and($log->created_at_display)->toBe('2026-11-08 21:00:00')
        ->and(AuditLogger::displayTimestamp($log->created_at))->toBe('2026-11-08 21:00:00');

    Carbon::setTestNow(); // Reset time
});

// Additional test: Never store file contents, passwords or full documents in metadata
it('never stores file contents, passwords, or full documents in metadata', function (): void {
    $this->actingAs($this->user);

    $doc = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Confidential Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $rawPdf = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF";
    $rawZip = "PK\x03\x04test_zip_contents";
    $hugeText = str_repeat('A', 15000);

    $log = AuditLogger::log('document.uploaded', $doc, [
        'password' => 'secret_password_123',
        'password_confirmation' => 'secret_password_123',
        'file_contents' => $rawPdf,
        'full_document' => $rawPdf,
        'raw_content' => $rawZip,
        'content' => $hugeText,
        'allowed_meta' => 'safe_value',
    ]);

    expect($log->metadata)->toHaveKey('allowed_meta')
        ->and($log->metadata['allowed_meta'])->toBe('safe_value')
        ->and($log->metadata)->not->toHaveKey('password')
        ->and($log->metadata)->not->toHaveKey('password_confirmation')
        ->and($log->metadata)->not->toHaveKey('file_contents')
        ->and($log->metadata)->not->toHaveKey('full_document')
        ->and($log->metadata)->not->toHaveKey('raw_content')
        ->and($log->metadata)->not->toHaveKey('content');
});
