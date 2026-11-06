<?php

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
        'legal_name' => 'Metadata Test Org',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 20,
        'business_activities' => ['51'],
    ]);

    $this->admin = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Admin User',
        'email' => 'admin@metadata-test.test',
        'password' => 'password',
        'role' => 'administrator',
    ]);

    $this->complianceUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance Officer',
        'email' => 'compliance@metadata-test.test',
        'password' => 'password',
        'role' => 'compliance_staff',
    ]);

    $this->generalUser = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'General Employee',
        'email' => 'employee@metadata-test.test',
        'password' => 'password',
        'role' => 'general_employee',
    ]);

    $this->validPayload = [
        'name' => 'Standard Operating Procedure',
        'category' => 'policy',
        'version' => '1.0.0',
        'access_level' => 'all_staff',
    ];
});

function createTestTxtFile(): UploadedFile
{
    $temp = tempnam(sys_get_temp_dir(), 'meta_test');
    file_put_contents($temp, "Metadata test valid text content.\n");

    return new UploadedFile($temp, 'document.txt', 'text/plain', null, true);
}

// 1. Name validation tests
it('rejects an empty document name with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'name' => '',
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Document name is required.',
        ]);

    expect(Document::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
});

it('rejects a document name shorter than 3 characters with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'name' => 'AB',
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Document name must be between 3 and 150 characters.',
        ]);

    expect(Document::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
});

it('rejects a document name longer than 150 characters with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'name' => str_repeat('A', 151),
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Document name must be between 3 and 150 characters.',
        ]);

    expect(Document::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
});

// 2. Category validation tests
it('rejects an empty category with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => '',
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Select a category.',
        ]);

    expect(Document::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
});

it('rejects an invalid category not in catalog with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => 'nonexistent_category',
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'The selected category is not valid.',
        ]);

    expect(Document::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
});

// 3. Version validation tests
it('rejects an empty version with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'version' => '',
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Version is required.',
        ]);

    expect(Document::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
});

it('rejects version with invalid format with exact message', function (string $invalidVersion): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'version' => $invalidVersion,
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Version must use the format 1, 1.0 or 1.0.0.',
        ]);

    expect(Document::count())->toBe(0);
    expect(count(Storage::disk('documents')->allFiles()))->toBe(0);
})->with(['v2', 'v1.0', '1.0.0.0', '1.', '.1', 'beta', '1.0-alpha']);

it('accepts valid version formats', function (string $validVersion): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'version' => $validVersion,
            'file' => $file,
        ]));

    $response->assertStatus(201);
    expect(DocumentVersion::first()->version_label)->toBe($validVersion);
})->with(['1', '1.0', '1.0.0', '2', '2.5', '10.3.1']);

// 4. Duplicate version on new version upload
it('rejects new version upload if version label already exists for the document with exact message', function (): void {
    $file1 = createTestTxtFile();

    // Create initial document with version 1.0.0
    $createResponse = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'version' => '1.0.0',
            'file' => $file1,
        ]));

    $createResponse->assertStatus(201);
    $documentId = $createResponse->json('id');
    $document = Document::findOrFail($documentId);

    $file2 = createTestTxtFile();

    // Attempt to upload version 1.0.0 again for the same document
    $response = $this->actingAs($this->admin)
        ->postJson("/api/documents/{$documentId}/versions", [
            'version' => '1.0.0',
            'file' => $file2,
        ]);

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Version 1.0.0 already exists for this document.',
        ]);

    // Ensure second version was not created
    expect(DocumentVersion::where('document_id', $documentId)->count())->toBe(1);
});

it('accepts a new unique version for an existing document', function (): void {
    $file1 = createTestTxtFile();

    $createResponse = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'version' => '1.0',
            'file' => $file1,
        ]));

    $createResponse->assertStatus(201);
    $documentId = $createResponse->json('id');

    $file2 = createTestTxtFile();

    $versionResponse = $this->actingAs($this->complianceUser)
        ->postJson("/api/documents/{$documentId}/versions", [
            'version' => '2.0',
            'file' => $file2,
        ]);

    $versionResponse->assertStatus(201);

    $versions = DocumentVersion::where('document_id', $documentId)->get();
    expect($versions)->toHaveCount(2);

    $v1 = $versions->where('version_label', '1.0')->first();
    $v2 = $versions->where('version_label', '2.0')->first();

    expect($v1->is_current)->toBeFalse();
    expect($v2->is_current)->toBeTrue();
});

// 5. Expiration date rules
it('rejects Permit/License document without expiration date with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => 'permit_license',
            'expiration_date' => null,
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Expiration date is required for Permit/License documents.',
        ]);

    expect(Document::count())->toBe(0);
});

it('rejects Certificate document without expiration date with exact message', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => 'certificate',
            'expiration_date' => null,
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Expiration date is required for Certificate documents.',
        ]);

    expect(Document::count())->toBe(0);
});

it('accepts Policy document without expiration date', function (): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => 'policy',
            'expiration_date' => null,
            'file' => $file,
        ]));

    $response->assertStatus(201);
    expect(Document::count())->toBe(1);
    expect(DocumentVersion::first()->expiration_date)->toBeNull();
});

it('rejects expiration date today or in the past with exact message', function (string $date): void {
    $file = createTestTxtFile();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => 'permit_license',
            'expiration_date' => $date,
            'file' => $file,
        ]));

    $response->assertStatus(422)
        ->assertJson([
            'message' => 'Expiration date must be a future date.',
        ]);

    expect(Document::count())->toBe(0);
})->with([
    fn () => now()->toDateString(), // today
    fn () => now()->subDay()->toDateString(), // yesterday
    '2020-01-01', // far past
]);

it('accepts a valid future expiration date', function (): void {
    $file = createTestTxtFile();
    $futureDate = now()->addYear()->toDateString();

    $response = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => 'permit_license',
            'expiration_date' => $futureDate,
            'file' => $file,
        ]));

    $response->assertStatus(201);
    expect(Document::count())->toBe(1);
    expect(DocumentVersion::first()->expiration_date->toDateString())->toBe($futureDate);
});

// 6. Automatic fields are not editable by client
it('automatically sets uploaded_at and uploaded_by ignoring client inputs', function (): void {
    $file = createTestTxtFile();

    $spoofedDate = '2020-01-01 00:00:00';
    $spoofedUserId = 99999;

    $response = $this->actingAs($this->complianceUser)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'file' => $file,
            'uploaded_at' => $spoofedDate,
            'uploaded_by' => $spoofedUserId,
        ]));

    $response->assertStatus(201);

    $version = DocumentVersion::first();
    expect($version->uploaded_by)->toBe($this->complianceUser->id);
    expect($version->uploaded_by)->not->toBe($spoofedUserId);
    expect($version->uploaded_at->toDateString())->toBe(now()->toDateString());
    expect($version->uploaded_at->toDateString())->not->toBe('2020-01-01');
});

// 7. Detail endpoint: displays metadata to authorized users
it('returns complete document metadata for detail page', function (): void {
    $futureDate = now()->addMonths(6)->toDateString();
    $file = createTestTxtFile();

    $uploadRes = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'name' => 'Environmental Health Policy',
            'category' => 'permit_license',
            'version' => '1.0.0',
            'access_level' => 'all_staff',
            'expiration_date' => $futureDate,
            'file' => $file,
        ]));

    $uploadRes->assertStatus(201);
    $docId = $uploadRes->json('id');

    $detailRes = $this->actingAs($this->admin)
        ->getJson("/api/documents/{$docId}");

    $detailRes->assertStatus(200)
        ->assertJson([
            'id' => $docId,
            'name' => 'Environmental Health Policy',
            'category' => 'permit_license',
            'category_name' => 'Permit/License',
            'visibility' => 'All staff',
            'current_version' => '1.0.0',
            'uploaded_by' => 'Admin User',
            'processing_status' => 'queued',
        ]);

    $data = $detailRes->json();
    expect($data['expiration_date_formatted'])->toBe(now()->addMonths(6)->format('d/m/Y'));
    expect($data['upload_date'])->toBe(now()->format('d/m/Y H:i'));
});

it('returns "No expiration" when expiration date is null', function (): void {
    $file = createTestTxtFile();

    $uploadRes = $this->actingAs($this->admin)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'category' => 'policy',
            'expiration_date' => null,
            'file' => $file,
        ]));

    $uploadRes->assertStatus(201);
    $docId = $uploadRes->json('id');

    $detailRes = $this->actingAs($this->admin)
        ->getJson("/api/documents/{$docId}");

    $detailRes->assertStatus(200)
        ->assertJson([
            'expiration_date_formatted' => 'No expiration',
        ]);
});

it('forbids general employee from viewing compliance_only document detail', function (): void {
    $file = createTestTxtFile();

    $uploadRes = $this->actingAs($this->complianceUser)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'access_level' => 'compliance_only',
            'file' => $file,
        ]));

    $uploadRes->assertStatus(201);
    $docId = $uploadRes->json('id');

    $response = $this->actingAs($this->generalUser)
        ->getJson("/api/documents/{$docId}");

    $response->assertStatus(403);
});

it('allows general employee to view all_staff document detail', function (): void {
    $file = createTestTxtFile();

    $uploadRes = $this->actingAs($this->complianceUser)
        ->postJson('/api/documents', array_merge($this->validPayload, [
            'access_level' => 'all_staff',
            'file' => $file,
        ]));

    $uploadRes->assertStatus(201);
    $docId = $uploadRes->json('id');

    $response = $this->actingAs($this->generalUser)
        ->getJson("/api/documents/{$docId}");

    $response->assertStatus(200);
});
