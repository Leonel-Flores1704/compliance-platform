<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use Database\Seeders\CatalogSeeder;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
    $organization = Organization::query()->create([
        'legal_name' => 'Constraint Test Organization', 'organization_type' => 'private_company',
        'industry' => 'technology', 'state' => '09', 'municipality' => 'Cuauhtemoc',
        'employees_count' => 10, 'business_activities' => ['51'],
    ]);
    $this->user = User::query()->create([
        'organization_id' => $organization->id, 'name' => 'Test Administrator',
        'email' => 'constraints@example.test', 'password' => 'password', 'role' => 'administrator',
    ]);
    $this->document = Document::query()->create([
        'organization_id' => $organization->id, 'name' => 'Constraint document',
        'category' => 'policy', 'access_level' => 'all_staff', 'created_by' => $this->user->id,
    ]);
});

it('rejects a document version whose document foreign key does not exist', function (): void {
    try {
        DocumentVersion::query()->create([
            'document_id' => 999999, 'version_label' => 'v1', 'storage_path' => 'documents/test.pdf',
            'original_filename' => 'test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
            'sha256' => str_repeat('a', 64), 'status' => 'queued', 'uploaded_by' => $this->user->id,
        ]);
        $this->fail('A foreign-key violation was expected.');
    } catch (QueryException $exception) {
        expect($exception->errorInfo[0] ?? null)->toBe('23503');
    }
});

it('rejects a second current version for the same document', function (): void {
    $attributes = [
        'document_id' => $this->document->id, 'storage_path' => 'documents/test.pdf',
        'original_filename' => 'test.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1,
        'sha256' => str_repeat('a', 64), 'status' => 'queued', 'uploaded_by' => $this->user->id,
        'is_current' => true,
    ];
    DocumentVersion::query()->create($attributes + ['version_label' => 'v1']);

    expect(fn () => DocumentVersion::query()->create($attributes + ['version_label' => 'v2']))
        ->toThrow(QueryException::class);
});
