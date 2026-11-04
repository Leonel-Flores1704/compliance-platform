<?php

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
    Storage::fake('documents');

    $this->organization = Organization::query()->create([
        'legal_name' => 'Download Test Organization',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 25,
        'business_activities' => ['51'],
    ]);

    $this->otherOrganization = Organization::query()->create([
        'legal_name' => 'Other Organization',
        'organization_type' => 'private_company',
        'industry' => 'services',
        'state' => '14',
        'municipality' => 'Guadalajara',
        'employees_count' => 10,
        'business_activities' => ['54'],
    ]);

    $this->admin = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Admin User',
        'email' => 'admin@download-test.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);

    $this->complianceUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance User',
        'email' => 'compliance@download-test.test',
        'password' => 'password',
        'role' => 'compliance_staff',
    ]);

    $this->generalUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'General Employee',
        'email' => 'general@download-test.test',
        'password' => 'password',
        'role' => 'general_employee',
    ]);

    $this->otherOrgUser = User::query()->create([
        'organization_id' => $this->otherOrganization->id,
        'name' => 'External User',
        'email' => 'external@download-test.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);
});

function createUploadedPdf(): UploadedFile
{
    $temp = tempnam(sys_get_temp_dir(), 'test_dl_pdf');
    $content = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\nstream\nAuthorized test PDF content for CMP-22 encryption test.\nendstream\n";
    file_put_contents($temp, $content);

    return new UploadedFile($temp, 'audit_report.pdf', 'application/pdf', null, true);
}

// 1. Authorized download returns file whose SHA-256 equals original
it('allows an authorized user to download a document version and validates SHA-256 and headers', function (): void {
    $file = createUploadedPdf();
    $originalContent = file_get_contents($file->getRealPath());
    $expectedSha256 = hash('sha256', $originalContent);

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Annual Compliance Audit',
        'category' => 'report',
        'access_level' => 'compliance_only',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $documentId = $uploadRes->json('id');
    $version = DocumentVersion::where('document_id', $documentId)->first();

    $downloadRes = $this->actingAs($this->complianceUser)
        ->get("/api/documents/{$documentId}/versions/{$version->id}/download");

    $downloadRes->assertStatus(200);
    $downloadRes->assertHeader('Content-Type', 'application/pdf');
    $downloadRes->assertHeader('Content-Disposition', 'attachment; filename="audit_report.pdf"');
    $downloadRes->assertHeader('X-Content-Type-Options', 'nosniff');

    $downloadedContent = $downloadRes->streamedContent();
    expect($downloadedContent)->toBe($originalContent);
    expect(hash('sha256', $downloadedContent))->toBe($expectedSha256);
});

// 2. Stored .bin file contains unreadable ciphertext and does not match original format
it('stores document file as encrypted unreadable binary ending in .bin', function (): void {
    $file = createUploadedPdf();
    $originalContent = file_get_contents($file->getRealPath());

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Security Audit',
        'category' => 'report',
        'access_level' => 'compliance_only',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $version = DocumentVersion::first();

    // Verify storage path format: documents/<org_id>/<doc_id>/<uuid>.bin
    expect($version->storage_path)->toMatch('#^documents/\d+/\d+/[a-f0-9-]{36}\.bin$#');
    expect(str_ends_with($version->storage_path, '.bin'))->toBeTrue();

    // Verify raw stored bytes
    $rawStored = Storage::disk('documents')->get($version->storage_path);
    expect($rawStored)->not->toBe($originalContent);
    expect(str_starts_with($rawStored, '%PDF'))->toBeFalse();
    expect(str_starts_with($rawStored, 'PK'))->toBeFalse();

    // Minimum size has libsodium secretstream header (24 bytes) + ciphertext overhead
    expect(strlen($rawStored))->toBeGreaterThan(strlen($originalContent));
});

// 3. Requesting /storage/documents/... or any guessed path returns 404
it('returns 404 when attempting to access /storage/documents or private paths', function (): void {
    $this->get('/storage/documents/nonexistent.bin')->assertStatus(404);
    $this->get('/storage/documents/1/1/secret.bin')->assertStatus(404);
    $this->get('/storage/private/documents/1.bin')->assertStatus(404);
});

// 4. General employee downloading compliance_only document gets 403 with exact text and audit record
it('denies general employee downloading compliance_only document with exact message and writes security audit log', function (): void {
    $file = createUploadedPdf();

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Confidential Financial Audit',
        'category' => 'report',
        'access_level' => 'compliance_only',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $documentId = $uploadRes->json('id');
    $version = DocumentVersion::first();

    $downloadRes = $this->actingAs($this->generalUser)
        ->getJson("/api/documents/{$documentId}/versions/{$version->id}/download");

    $downloadRes->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to download this document.',
        ]);

    // Check security audit log record
    $auditLog = AuditLog::where('action', 'document.download_denied')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->category)->toBe('security');
    expect($auditLog->resource_type)->toBe('document');
    expect($auditLog->resource_id)->toBe($documentId);
    expect($auditLog->user_id)->toBe($this->generalUser->id);
    expect($auditLog->metadata['access_level'])->toBe('compliance_only');
    expect($auditLog->metadata['user_role'])->toBe('general_employee');
});

// 5. General employee can download all_staff document
it('allows general employee to download all_staff document', function (): void {
    $file = createUploadedPdf();
    $originalContent = file_get_contents($file->getRealPath());

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Employee Handbook',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $documentId = $uploadRes->json('id');
    $version = DocumentVersion::first();

    $downloadRes = $this->actingAs($this->generalUser)
        ->get("/api/documents/{$documentId}/versions/{$version->id}/download");

    $downloadRes->assertStatus(200);
    expect($downloadRes->streamedContent())->toBe($originalContent);
});

// 6. User from different organization gets 403 with exact text and audit log
it('denies user from another organization with exact message and audit record', function (): void {
    $file = createUploadedPdf();

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Internal Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $documentId = $uploadRes->json('id');
    $version = DocumentVersion::first();

    $downloadRes = $this->actingAs($this->otherOrgUser)
        ->getJson("/api/documents/{$documentId}/versions/{$version->id}/download");

    $downloadRes->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to download this document.',
        ]);

    $auditLog = AuditLog::where('action', 'document.download_denied')->first();
    expect($auditLog)->not->toBeNull();
    expect($auditLog->category)->toBe('security');
    expect($auditLog->user_id)->toBe($this->otherOrgUser->id);
});

// 7. Non-existent document or version returns 404 with exact message
it('returns 404 with exact message when document does not exist', function (): void {
    $response = $this->actingAs($this->admin)
        ->getJson('/api/documents/999999/versions/1/download');

    $response->assertStatus(404)
        ->assertJson([
            'message' => 'Document not found.',
        ]);
});

it('returns 404 with exact message when version does not exist for the document', function (): void {
    $file = createUploadedPdf();

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Workplace Safety',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $documentId = $uploadRes->json('id');

    $response = $this->actingAs($this->admin)
        ->getJson("/api/documents/{$documentId}/versions/999999/download");

    $response->assertStatus(404)
        ->assertJson([
            'message' => 'Document not found.',
        ]);
});

// 8. Decryption fails (tampered file) returns 500 with exact message
it('returns 500 with exact message when stored encrypted file is corrupted', function (): void {
    $file = createUploadedPdf();

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Tamper Test Document',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $documentId = $uploadRes->json('id');
    $version = DocumentVersion::first();

    // Tamper with the stored ciphertext
    $ciphertext = Storage::disk('documents')->get($version->storage_path);
    $tampered = substr_replace($ciphertext, "\xFF\xFF\xFF", 25, 3);
    Storage::disk('documents')->put($version->storage_path, $tampered);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/documents/{$documentId}/versions/{$version->id}/download");

    $response->assertStatus(500)
        ->assertJson([
            'message' => 'The file could not be retrieved. Please contact your administrator.',
        ]);
});

// 9. SHA-256 mismatch returns 500 with exact message
it('returns 500 with exact message when SHA-256 verification fails', function (): void {
    $file = createUploadedPdf();

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Checksum Test Document',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $uploadRes->assertStatus(201);
    $documentId = $uploadRes->json('id');
    $version = DocumentVersion::first();

    // Tamper with expected SHA-256 in the database
    $version->update(['sha256' => str_repeat('0', 64)]);

    $response = $this->actingAs($this->admin)
        ->getJson("/api/documents/{$documentId}/versions/{$version->id}/download");

    $response->assertStatus(500)
        ->assertJson([
            'message' => 'The file could not be retrieved. Please contact your administrator.',
        ]);
});

// 10. Unauthenticated request returns 401
it('rejects unauthenticated download requests with 401', function (): void {
    $response = $this->getJson('/api/documents/1/versions/1/download');
    $response->assertStatus(401);
});

// 11. Can download distinct versions of the same document
it('can download distinct versions of the same document independently', function (): void {
    $temp1 = tempnam(sys_get_temp_dir(), 'test_v1');
    file_put_contents($temp1, "Version 1.0 Content\n");
    $file1 = new UploadedFile($temp1, 'v1.txt', 'text/plain', null, true);

    $uploadRes = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Versioned Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0',
        'file' => $file1,
    ]);

    $uploadRes->assertStatus(201);
    $docId = $uploadRes->json('id');
    $v1 = DocumentVersion::where('document_id', $docId)->first();

    $temp2 = tempnam(sys_get_temp_dir(), 'test_v2');
    file_put_contents($temp2, "Version 2.0 Updated Content\n");
    $file2 = new UploadedFile($temp2, 'v2.txt', 'text/plain', null, true);

    $v2Res = $this->actingAs($this->complianceUser)
        ->postJson("/api/documents/{$docId}/versions", [
            'version' => '2.0',
            'file' => $file2,
        ]);

    $v2Res->assertStatus(201);
    $v2 = DocumentVersion::where('document_id', $docId)->where('version_label', '2.0')->first();

    // Download v1
    $dl1 = $this->actingAs($this->admin)->get("/api/documents/{$docId}/versions/{$v1->id}/download");
    $dl1->assertStatus(200);
    expect($dl1->streamedContent())->toBe("Version 1.0 Content\n");

    // Download v2
    $dl2 = $this->actingAs($this->admin)->get("/api/documents/{$docId}/versions/{$v2->id}/download");
    $dl2->assertStatus(200);
    expect($dl2->streamedContent())->toBe("Version 2.0 Updated Content\n");
});

// 12. Version belonging to another document returns 404
it('returns 404 when versionId belongs to a different document', function (): void {
    $temp1 = tempnam(sys_get_temp_dir(), 'test_doc1');
    file_put_contents($temp1, "Doc 1\n");
    $file1 = new UploadedFile($temp1, 'doc1.txt', 'text/plain', null, true);

    $res1 = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'First Document',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0',
        'file' => $file1,
    ]);
    $doc1Id = $res1->json('id');

    $temp2 = tempnam(sys_get_temp_dir(), 'test_doc2');
    file_put_contents($temp2, "Doc 2\n");
    $file2 = new UploadedFile($temp2, 'doc2.txt', 'text/plain', null, true);

    $res2 = $this->actingAs($this->admin)->postJson('/api/documents', [
        'name' => 'Second Document',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0',
        'file' => $file2,
    ]);
    $doc2Id = $res2->json('id');
    $v2 = DocumentVersion::where('document_id', $doc2Id)->first();

    // Attempting to download Doc 2's version using Doc 1's ID
    $response = $this->actingAs($this->admin)
        ->getJson("/api/documents/{$doc1Id}/versions/{$v2->id}/download");

    $response->assertStatus(404)
        ->assertJson(['message' => 'Document not found.']);
});
