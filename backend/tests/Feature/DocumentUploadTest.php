<?php

use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentEncryptionService;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
    Storage::fake('documents');

    $this->organization = Organization::query()->create([
        'legal_name' => 'Upload Test Organization',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 15,
        'business_activities' => ['51'],
    ]);

    $this->admin = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Admin User',
        'email' => 'admin@upload-test.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);

    $this->complianceUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance User',
        'email' => 'compliance@upload-test.test',
        'password' => 'password',
        'role' => 'compliance_staff',
    ]);

    $this->generalUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'General Employee',
        'email' => 'general@upload-test.test',
        'password' => 'password',
        'role' => 'general_employee',
    ]);

    $this->validMetadata = [
        'name' => 'Compliance Policy 2026',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'version' => '1.0.0',
    ];
});

function makePdfFile(int $sizeBytes, string $contentPrefix = ''): UploadedFile
{
    $temp = tempnam(sys_get_temp_dir(), 'test_pdf');
    $handle = fopen($temp, 'wb');
    $header = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
    if ($contentPrefix !== '') {
        $header .= $contentPrefix."\n";
    }
    fwrite($handle, $header);
    ftruncate($handle, $sizeBytes);
    fclose($handle);

    return new UploadedFile($temp, 'test.pdf', 'application/pdf', null, true);
}

function makeDocxFile(bool $withMacro = false): UploadedFile
{
    $temp = tempnam(sys_get_temp_dir(), 'test_docx');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body><w:p><w:r><w:t>Hello</w:t></w:r></w:p></w:body></w:document>');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        .'</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        .'</Relationships>');
    if ($withMacro) {
        $zip->addFromString('word/vbaProject.bin', 'DUMMY_VBA_BIN_MACRO');
    }
    $zip->close();

    return new UploadedFile($temp, 'test.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);
}

function makeTxtFile(string $content = "Valid test document content\n"): UploadedFile
{
    $temp = tempnam(sys_get_temp_dir(), 'test_txt');
    file_put_contents($temp, $content);

    return new UploadedFile($temp, 'test.txt', 'text/plain', null, true);
}

function assertRejectionLeavesStorageAndDatabaseClean(): void
{
    expect(Document::count())->toBe(0);
    expect(DocumentVersion::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
    expect(AuditLog::where('action', 'document.uploaded')->count())->toBe(0);
}

// 1. PDF with exactly 26,214,400 bytes: accepted
it('accepts a valid PDF of exactly 26,214,400 bytes', function (): void {
    $file = makePdfFile(26214400);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(201)
        ->assertJson([
            'message' => 'Document uploaded successfully. It is now being processed.',
            'document' => [
                'name' => 'Compliance Policy 2026',
                'category' => 'policy',
                'access_level' => 'all_staff',
                'status' => 'queued',
                'version_label' => '1.0.0',
            ],
        ]);

    expect(Document::count())->toBe(1);
    expect(DocumentVersion::count())->toBe(1);
    expect(DocumentVersion::first()->size_bytes)->toBe(26214400);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(1);
    expect(AuditLog::where('action', 'document.uploaded')->count())->toBe(1);
});

// 2. Valid DOCX: accepted
it('accepts a valid DOCX file', function (): void {
    $file = makeDocxFile(false);

    $response = $this->actingAs($this->complianceUser)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(201)
        ->assertJson([
            'message' => 'Document uploaded successfully. It is now being processed.',
            'document' => [
                'status' => 'queued',
                'version_label' => '1.0.0',
            ],
        ]);

    expect(Document::count())->toBe(1);
    expect(DocumentVersion::first()->mime_type)->toBe('application/vnd.openxmlformats-officedocument.wordprocessingml.document');
    expect(count(Storage::disk('documents')->allFiles()))->toBe(1);
});

// 3. Valid TXT: accepted
it('accepts a valid TXT file', function (): void {
    $file = makeTxtFile("Valid text file content.\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(201)
        ->assertJson([
            'message' => 'Document uploaded successfully. It is now being processed.',
            'document' => [
                'status' => 'queued',
                'version_label' => '1.0.0',
            ],
        ]);

    expect(Document::count())->toBe(1);
    expect(DocumentVersion::first()->mime_type)->toBe('text/plain');
});

// 4. PDF with 26,214,401 bytes: rejected with exact message
it('rejects a PDF with 26,214,401 bytes with the exact message', function (): void {
    $file = makePdfFile(26214401);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file exceeds the 25 MB limit (26,214,400 bytes).',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 5. PNG: rejected with exact message
it('rejects a PNG file with the exact message', function (): void {
    $temp = tempnam(sys_get_temp_dir(), 'test_png');
    file_put_contents($temp, "\x89PNG\r\n\x1a\n\x00\x00\x00\rIHDR");
    $file = new UploadedFile($temp, 'photo.png', 'image/png', null, true);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Only PDF, DOCX and TXT files are allowed.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 6. EXE renamed to PDF: rejected with exact message
it('rejects an EXE renamed to PDF with the exact message', function (): void {
    $temp = tempnam(sys_get_temp_dir(), 'test_exe_pdf');
    file_put_contents($temp, "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF");
    $file = new UploadedFile($temp, 'document.pdf', 'application/pdf', null, true);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file content does not match its extension (.pdf).',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 7. DOCM renamed to DOCX: rejected with exact message
it('rejects a DOCM renamed to DOCX with the exact message', function (): void {
    $temp = tempnam(sys_get_temp_dir(), 'test_docm_docx');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="xml" ContentType="application/xml"/><Override PartName="/word/document.xml" ContentType="application/vnd.ms-word.document.macroEnabled.main+xml"/></Types>');
    $zip->addFromString('word/vbaProject.bin', 'DUMMY_VBA_BIN_MACRO');
    $zip->close();
    $file = new UploadedFile($temp, 'document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422);
    $msg = $response->json('message');
    expect($msg === 'The file content does not match its extension (.docx).' || $msg === 'The file was rejected because it contains executable content.')->toBeTrue();

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 8. Empty TXT: rejected with exact message
it('rejects an empty TXT file with the exact message', function (): void {
    $temp = tempnam(sys_get_temp_dir(), 'test_empty_txt');
    file_put_contents($temp, '');
    $file = new UploadedFile($temp, 'empty.txt', 'text/plain', null, true);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file is empty.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 9. Windows executable signature: rejected with exact message
it('rejects a file starting with Windows executable signature MZ', function (): void {
    $file = makeTxtFile("MZThis is executable masquerading as text\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 10. ELF executable signature: rejected with exact message
it('rejects a file starting with ELF executable signature', function (): void {
    $file = makeTxtFile("\x7FELFThis is an ELF binary\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 11. Script signature: rejected with exact message
it('rejects a file starting with shebang script signature', function (): void {
    $file = makeTxtFile("#!/bin/bash\necho 'running script'\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 12. DOCX containing word/vbaProject.bin: rejected with exact message
it('rejects a DOCX containing word/vbaProject.bin with the exact message', function (): void {
    $file = makeDocxFile(true);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 13. PDF containing /JavaScript: rejected with exact message
it('rejects a PDF containing /JavaScript action with the exact message', function (): void {
    $file = makePdfFile(2048, "3 0 obj\n<< /Type /Action /S /JavaScript /JS (app.alert('evil')) >>\nendobj");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 14. PDF containing /Launch: rejected with exact message
it('rejects a PDF containing /Launch action with the exact message', function (): void {
    $file = makePdfFile(2048, "3 0 obj\n<< /Type /Action /S /Launch /F (calc.exe) >>\nendobj");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// 15. User without upload permission (general employee): 403 with exact message
it('returns HTTP 403 when a general employee attempts to upload', function (): void {
    $file = makeTxtFile("Valid text file content.\n");

    $response = $this->actingAs($this->generalUser)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(403)
        ->assertJson([
            'message' => 'You do not have permission to upload documents.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// Additional test: No file selected returns 422 with exact message
it('rejects upload when no file is selected', function (): void {
    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Select a file to upload.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// Additional test: Tenant isolation
it('strictly associates uploaded document with user organization', function (): void {
    $otherOrg = Organization::query()->create([
        'legal_name' => 'Other Organization',
        'organization_type' => 'private_company',
        'industry' => 'services',
        'state' => '14',
        'municipality' => 'Guadalajara',
        'employees_count' => 50,
        'business_activities' => ['54'],
    ]);

    $file = makeTxtFile("Tenant test file content.\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + [
            'file' => $file,
            'organization_id' => $otherOrg->id, // Attempt to inject foreign organization
        ]);

    $response->assertStatus(201);
    $document = Document::first();
    expect($document->organization_id)->toBe($this->organization->id);
    expect($document->organization_id)->not->toBe($otherOrg->id);
});

// Additional test: Metadata validation with required expiration date for permit_license
it('requires expiration date for permit_license and certificate categories', function (): void {
    $file = makeTxtFile("Permit document\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', [
            'name' => 'Operating Permit',
            'category' => 'permit_license',
            'access_level' => 'compliance_only',
            'version' => '1.0.0',
            'file' => $file,
        ]);

    $response->assertStatus(422);
    $response->assertJsonValidationErrors(['expiration_date']);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// Additional test: Unauthenticated request returns 401
it('rejects unauthenticated upload requests with 401', function (): void {
    $file = makeTxtFile("Unauthenticated test\n");

    $response = $this->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(401);
    assertRejectionLeavesStorageAndDatabaseClean();
});

// Additional hardening test: PDF with compressed Object Stream containing /JavaScript
it('rejects a PDF with compressed object stream containing /JavaScript', function (): void {
    $streamPayload = gzcompress("1 0 obj\n<< /Type /Action /S /JavaScript /JS (app.alert(1)) >>\nendobj");
    $pdfContent = "%PDF-1.5\n1 0 obj\n<< /Type /ObjStm /Filter /FlateDecode >>\nstream\n".$streamPayload."\nendstream\nendobj\n%%EOF";
    $temp = tempnam(sys_get_temp_dir(), 'test_compressed_pdf');
    file_put_contents($temp, $pdfContent);
    $file = new UploadedFile($temp, 'compressed.pdf', 'application/pdf', null, true);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// Additional hardening test: DOCX with custom relationship target for vbaProject
it('rejects a DOCX with custom relationship target for vbaProject', function (): void {
    $temp = tempnam(sys_get_temp_dir(), 'test_docx_custom_rel');
    $zip = new ZipArchive;
    $zip->open($temp, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $zip->addFromString('word/document.xml', '<w:document xmlns:w="http://schemas.openxmlformats.org/wordprocessingml/2006/main"><w:body/></w:document>');
    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        .'<Default Extension="xml" ContentType="application/xml"/>'
        .'<Override PartName="/word/document.xml" ContentType="application/vnd.openxmlformats-officedocument.wordprocessingml.document.main+xml"/>'
        .'</Types>');
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="word/document.xml"/>'
        .'</Relationships>');
    $zip->addFromString('word/_rels/document.xml.rels', '<?xml version="1.0" encoding="UTF-8"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.microsoft.com/office/2006/relationships/vbaProject" Target="custom_macro_name.dat"/></Relationships>');
    $zip->addFromString('word/custom_macro_name.dat', 'BINARY_MACRO_PAYLOAD');
    $zip->close();
    $file = new UploadedFile($temp, 'document.docx', 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', null, true);

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// Additional hardening test: Script file with UTF-8 BOM
it('rejects a script file starting with UTF-8 BOM and shebang', function (): void {
    $file = makeTxtFile("\xEF\xBB\xBF#!/bin/bash\necho 'bom script'\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The file was rejected because it contains executable content.',
        ]);

    assertRejectionLeavesStorageAndDatabaseClean();
});

// Additional test: verify storage path consistency between database and disk
it('can retrieve stored file from documents disk using version storage_path', function (): void {
    $file = makeTxtFile("Verifying storage path consistency\n");

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', $this->validMetadata + ['file' => $file]);

    $response->assertStatus(201);
    $version = DocumentVersion::first();
    expect(Storage::disk('documents')->exists($version->storage_path))->toBeTrue();
    expect(str_ends_with($version->storage_path, '.bin'))->toBeTrue();

    // Raw stored content is encrypted binary, not raw plaintext
    $rawCiphertext = Storage::disk('documents')->get($version->storage_path);
    expect($rawCiphertext)->not->toBe("Verifying storage path consistency\n");

    // Decrypting through DocumentEncryptionService restores original content
    $stream = app(DocumentEncryptionService::class)->decryptAndVerify($version);
    expect(stream_get_contents($stream))->toBe("Verifying storage path consistency\n");
});
