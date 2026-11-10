<?php

use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentVersionProcessor;
use Database\Seeders\CatalogSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'services.ai.url' => 'http://ai-service:8001',
        'services.ai.token' => 'test-secret-token',
    ]);

    $this->seed(CatalogSeeder::class);
    $organization = Organization::query()->create([
        'legal_name' => 'Extraction Test Organization', 'organization_type' => 'private_company',
        'industry' => 'technology', 'state' => '09', 'municipality' => 'Cuauhtemoc',
        'employees_count' => 10, 'business_activities' => ['51'],
    ]);
    $user = User::query()->create([
        'organization_id' => $organization->id, 'name' => 'Compliance Staff',
        'email' => 'extraction@example.test', 'password' => 'password', 'role' => 'compliance_staff',
    ]);
    $document = Document::query()->create([
        'organization_id' => $organization->id, 'name' => 'Security policy',
        'category' => 'policy', 'access_level' => 'all_staff', 'created_by' => $user->id,
    ]);
    $this->version = DocumentVersion::query()->create([
        'document_id' => $document->id, 'version_label' => 'v1', 'storage_path' => 'documents/policy.pdf',
        'original_filename' => 'policy.pdf', 'mime_type' => 'application/pdf', 'size_bytes' => 1024,
        'sha256' => str_repeat('a', 64), 'status' => 'queued', 'uploaded_by' => $user->id,
    ]);
});

function fakeIngestResponse(array $body, int $status): void
{
    Http::fake(['http://ai-service:8001/v1/documents/ingest' => Http::response($body, $status)]);
}

it('marks a password-protected file as Processing Failed with the exact message and no chunks', function (): void {
    fakeIngestResponse([
        'error_code' => 'EXTRACTION_FAILED',
        'message' => 'The file could not be read. It may be damaged or password-protected.',
    ], 422);

    // Binary content with null bytes, like a real PDF.
    $version = app(DocumentVersionProcessor::class)->process($this->version, "%PDF-1.7\x00\x01\x02encrypted");

    $version->refresh();
    expect($version->status)->toBe('processing_failed')
        ->and($version->statusLabel())->toBe('Processing Failed')
        ->and($version->failure_reason)->toBe('The file could not be read. It may be damaged or password-protected.')
        ->and($version->processed_at)->not->toBeNull()
        ->and(DB::table('document_chunks')->where('document_version_id', $version->id)->count())->toBe(0);
});

it('stores the no-text message when the document has no extractable text', function (): void {
    fakeIngestResponse([
        'error_code' => 'EXTRACTION_FAILED',
        'message' => 'No text could be extracted from this document.',
    ], 422);

    $version = app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    expect($version->refresh()->status)->toBe('processing_failed')
        ->and($version->failure_reason)->toBe('No text could be extracted from this document.');
});

it('removes chunks left by a failed attempt so the version is never indexed', function (): void {
    $document = $this->version->document;
    $embedding = '['.implode(',', array_fill(0, (int) config('ai.embedding_dimensions'), 0)).']';
    DB::table('document_chunks')->insert([
        'organization_id' => $document->organization_id, 'document_id' => $document->id,
        'document_version_id' => $this->version->id, 'chunk_index' => 0, 'content' => 'stale',
        'embedding' => $embedding,
    ]);
    fakeIngestResponse([
        'error_code' => 'EXTRACTION_FAILED',
        'message' => 'The file could not be read. It may be damaged or password-protected.',
    ], 422);

    app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    expect(DB::table('document_chunks')->where('document_version_id', $this->version->id)->count())->toBe(0);
});

it('never stores an unexpected AI message as the failure reason', function (): void {
    fakeIngestResponse(['error_code' => 'EXTRACTION_FAILED', 'message' => 'pdfminer: PDFSyntaxError at byte 12'], 422);

    $version = app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    expect($version->refresh()->failure_reason)
        ->toBe('The file could not be read. It may be damaged or password-protected.');
});

it('marks the version as processed and sends its metadata when extraction succeeds', function (): void {
    fakeIngestResponse(['status' => 'processed', 'chunks' => 3, 'pages' => 3, 'ocr_used' => false], 200);

    $version = app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    expect($version->refresh()->status)->toBe('processed')
        ->and($version->failure_reason)->toBeNull()
        ->and($version->processed_at)->not->toBeNull();

    Http::assertSent(function (Request $request): bool {
        $body = $request->body();

        return str_contains($body, 'name="document_version_id"')
            && str_contains($body, (string) $this->version->id)
            && str_contains($body, 'name="access_level"')
            && str_contains($body, 'all_staff');
    });
});

// --- CMP-29: indexing result and embedding failures -----------------------------------

it('marks the version as Processing Failed with the indexing message when embeddings fail', function (): void {
    fakeIngestResponse([
        'error_code' => 'EMBEDDING_FAILED',
        'message' => 'The document could not be indexed.',
    ], 502);

    $version = app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    $version->refresh();
    expect($version->status)->toBe('processing_failed')
        ->and($version->statusLabel())->toBe('Processing Failed')
        ->and($version->failure_reason)->toBe('The document text could not be indexed for the AI assistant.')
        ->and($version->chunk_count)->toBe(0)
        ->and(DB::table('document_chunks')->where('document_version_id', $version->id)->count())->toBe(0);

    $failedVersionsWithChunks = DB::table('document_chunks as c')
        ->join('document_versions as v', 'v.id', '=', 'c.document_version_id')
        ->where('v.status', 'processing_failed')
        ->count();
    expect($failedVersionsWithChunks)->toBe(0);
});

it('stores the number of chunks and pages returned by the AI service', function (): void {
    fakeIngestResponse(['status' => 'processed', 'chunks' => 7, 'pages' => 4, 'ocr_used' => false], 200);

    $version = app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    expect($version->refresh()->status)->toBe('processed')
        ->and($version->chunk_count)->toBe(7)
        ->and($version->page_count)->toBe(4);
});

it('activates the chunks of the current version through set-current', function (): void {
    $this->version->forceFill(['is_current' => true])->save();
    Http::fake([
        'http://ai-service:8001/v1/documents/ingest' => Http::response(['status' => 'processed', 'chunks' => 3, 'pages' => 2, 'ocr_used' => false], 200),
        'http://ai-service:8001/v1/documents/*/set-current' => Http::response(['status' => 'ok'], 200),
    ]);

    app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    Http::assertSent(fn (Request $request): bool => $request->url() === "http://ai-service:8001/v1/documents/{$this->version->document_id}/set-current"
        && $request['document_version_id'] === $this->version->id);
});

it('does not activate chunks of a version that is not current', function (): void {
    fakeIngestResponse(['status' => 'processed', 'chunks' => 3, 'pages' => 2, 'ocr_used' => false], 200);

    app(DocumentVersionProcessor::class)->process($this->version, 'contents');

    Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), 'set-current'));
});
