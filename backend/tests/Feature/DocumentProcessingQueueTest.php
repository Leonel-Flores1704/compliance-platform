<?php

use App\Jobs\ProcessDocumentVersion;
use App\Models\Alert;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\User;
use App\Services\DocumentEncryptionService;
use App\Services\DocumentVersionProcessor;
use App\Services\Exceptions\AiServiceExtractionFailedException;
use Database\Seeders\CatalogSeeder;
use Illuminate\Contracts\Bus\Dispatcher;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(CatalogSeeder::class);
    Storage::fake('documents');

    $this->organization = Organization::query()->create([
        'legal_name' => 'Queue Test Organization',
        'organization_type' => 'private_company',
        'industry' => 'technology',
        'state' => '09',
        'municipality' => 'Cuauhtemoc',
        'employees_count' => 20,
        'business_activities' => ['51'],
    ]);

    $this->user = User::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Compliance Officer',
        'email' => 'officer@queue-test.test',
        'password' => 'password',
        'role' => 'compliance_staff',
    ]);

    config([
        'services.ai.url' => 'http://ai-service:8001',
        'services.ai.token' => 'test-secret-token',
    ]);
});

function makeTestPdf(int $sizeBytes = 1024): UploadedFile
{
    $temp = tempnam(sys_get_temp_dir(), 'test_pdf');
    $handle = fopen($temp, 'wb');
    $header = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n2 0 obj\n<< /Type /Pages /Kids [] /Count 0 >>\nendobj\n";
    fwrite($handle, $header);
    if ($sizeBytes > strlen($header)) {
        ftruncate($handle, $sizeBytes);
    }
    fclose($handle);

    return new UploadedFile($temp, 'test.pdf', 'application/pdf', null, true);
}

/**
 * Acceptance Criterion 1 (NFR-010):
 * For 20 test uploads, queued_at - uploaded_at is 5 seconds or less in every case.
 */
it('ensures queued_at - uploaded_at is 5 seconds or less for 20 test uploads (AC 1)', function (): void {
    $this->actingAs($this->user);

    $isPgsql = DB::connection()->getDriverName() === 'pgsql';

    for ($i = 1; $i <= 20; $i++) {
        $file = makeTestPdf(500);

        $response = $this->postJson('/api/documents', [
            'name' => "Document Number {$i}",
            'category' => 'policy',
            'access_level' => 'all_staff',
            'version' => "1.0.{$i}",
            'file' => $file,
        ]);

        $response->assertStatus(201);
    }

    expect(Document::count())->toBe(20);
    expect(DocumentVersion::count())->toBe(20);

    // SQL query result for NFR-010 verification evidence
    $diffSql = $isPgsql
        ? 'EXTRACT(EPOCH FROM (queued_at - uploaded_at)) AS diff_seconds'
        : "(strftime('%s', queued_at) - strftime('%s', uploaded_at)) AS diff_seconds";

    $results = DB::table('document_versions')
        ->selectRaw("id, document_id, version_label, uploaded_at, queued_at, {$diffSql}")
        ->orderBy('id', 'asc')
        ->get();

    expect($results)->toHaveCount(20);

    foreach ($results as $row) {
        expect($row->queued_at)->not->toBeNull();
        expect($row->uploaded_at)->not->toBeNull();
        $diff = (float) $row->diff_seconds;
        expect($diff)->toBeLessThanOrEqual(5.0);
        expect($diff)->toBeGreaterThanOrEqual(0.0);
    }
});

/**
 * Acceptance Criterion 2:
 * Stopping Redis and uploading a valid file marks the version "Processing Failed"
 * with failure_reason 'queue_unavailable' and the uploader receives the exact notification:
 * We could not start processing "<document name>". Please upload the file again or contact your administrator.
 */
it('marks version as Processing Failed with queue_unavailable and sends exact notification when Redis is unavailable (AC 2)', function (): void {
    $this->actingAs($this->user);

    // Mock Dispatcher to simulate Redis connection failure upon queue dispatch
    $dispatcher = Mockery::mock(Dispatcher::class);
    $dispatcher->shouldReceive('dispatch')
        ->andThrow(new RuntimeException('Connection refused [tcp://redis:6379]'));
    app()->instance(Dispatcher::class, $dispatcher);

    $file = makeTestPdf(500);

    $response = $this->postJson('/api/documents', [
        'name' => 'Annual Supply Contract',
        'category' => 'contract',
        'access_level' => 'all_staff',
        'version' => '1.0.0',
        'file' => $file,
    ]);

    $response->assertStatus(201);

    $version = DocumentVersion::first();
    expect($version)->not->toBeNull();
    expect($version->status)->toBe('processing_failed');
    expect($version->statusLabel())->toBe('Processing Failed');
    expect($version->failure_reason)->toBe('queue_unavailable');

    $alert = Alert::where('document_version_id', $version->id)->first();
    expect($alert)->not->toBeNull();
    expect($alert->user_id)->toBe($this->user->id);
    expect($alert->type)->toBe('queue_unavailable');
    expect($alert->message)->toBe('We could not start processing "Annual Supply Contract". Please upload the file again or contact your administrator.');
});

/**
 * Acceptance Criterion 3:
 * A document whose ingest fails 3 times ends as "Processing Failed" with the reason and the exact notification:
 * Processing failed for "<document name>" (version <label>): <reason>.
 */
it('marks version as Processing Failed with reason and exact notification when ingest fails after 3 attempts (AC 3)', function (): void {
    // 1. Create document and version
    $document = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Damaged Financial Report',
        'category' => 'report',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    // Store encrypted file on documents disk
    $storagePath = "documents/{$document->organization_id}/{$document->id}/test.bin";
    $plainText = '%PDF-1.4 damaged file content';
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_doc');
    file_put_contents($tmpFile, $plainText);
    app(DocumentEncryptionService::class)->encryptFile($tmpFile, $storagePath);
    unlink($tmpFile);

    $version = DocumentVersion::query()->create([
        'document_id' => $document->id,
        'version_label' => '2.1.0',
        'storage_path' => $storagePath,
        'original_filename' => 'financial_report.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($plainText),
        'sha256' => hash('sha256', $plainText),
        'status' => 'queued',
        'is_current' => true,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now(),
        'queued_at' => now(),
    ]);

    $job = new ProcessDocumentVersion($version);

    // Queue worker properties
    expect($job->queue)->toBe('documents');
    expect($job->tries)->toBe(3);
    expect($job->backoff)->toBe([30, 120, 300]);
    expect($job->timeout)->toBe(300);

    // Mock FastAPI ingest failure (e.g. damaged or password-protected)
    Http::fake([
        'http://ai-service:8001/v1/documents/ingest' => Http::response([
            'error_code' => 'EXTRACTION_FAILED',
            'message' => 'The file could not be read. It may be damaged or password-protected.',
        ], 422),
    ]);

    // Simulate 3 attempts failing
    for ($attempt = 1; $attempt <= 3; $attempt++) {
        try {
            $job->handle(app(DocumentEncryptionService::class), app(DocumentVersionProcessor::class));
            $this->fail("Attempt {$attempt} was expected to throw an exception.");
        } catch (Throwable $e) {
            expect($e->getMessage())->toBe('The file could not be read. It may be damaged or password-protected.');
        }
    }

    // Call final failure handler after tries are exhausted
    $job->failed(new AiServiceExtractionFailedException('The file could not be read. It may be damaged or password-protected.'));

    $version->refresh();
    expect($version->status)->toBe('processing_failed');
    expect($version->statusLabel())->toBe('Processing Failed');
    expect($version->failure_reason)->toBe('The file could not be read. It may be damaged or password-protected.');
    expect($version->chunk_count)->toBe(0);

    $alert = Alert::where('document_version_id', $version->id)->first();
    expect($alert)->not->toBeNull();
    expect($alert->user_id)->toBe($this->user->id);
    expect($alert->type)->toBe('processing_failed');
    expect($alert->message)->toBe('Processing failed for "Damaged Financial Report" (version 2.1.0): The file could not be read. It may be damaged or password-protected.');
});

/**
 * Success path: A valid document version is processed successfully in the background.
 */
it('processes valid document successfully, marks status processed and activates current version', function (): void {
    $document = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Information Security Policy',
        'category' => 'policy',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $storagePath = "documents/{$document->organization_id}/{$document->id}/valid.bin";
    $plainText = '%PDF-1.4 Valid Document Content';
    $tmpFile = tempnam(sys_get_temp_dir(), 'test_doc');
    file_put_contents($tmpFile, $plainText);
    app(DocumentEncryptionService::class)->encryptFile($tmpFile, $storagePath);
    unlink($tmpFile);

    $version = DocumentVersion::query()->create([
        'document_id' => $document->id,
        'version_label' => '1.0.0',
        'storage_path' => $storagePath,
        'original_filename' => 'infosec.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => strlen($plainText),
        'sha256' => hash('sha256', $plainText),
        'status' => 'queued',
        'is_current' => true,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now(),
        'queued_at' => now(),
    ]);

    Http::fake([
        'http://ai-service:8001/v1/documents/ingest' => Http::response([
            'status' => 'processed',
            'chunks' => 5,
            'pages' => 2,
            'ocr_used' => false,
        ], 200),
        'http://ai-service:8001/v1/documents/*/set-current' => Http::response([
            'status' => 'ok',
        ], 200),
    ]);

    $job = new ProcessDocumentVersion($version);
    $job->handle(app(DocumentEncryptionService::class), app(DocumentVersionProcessor::class));

    $version->refresh();
    expect($version->status)->toBe('processed');
    expect($version->statusLabel())->toBe('Processed');
    expect($version->processed_at)->not->toBeNull();
    expect($version->chunk_count)->toBe(5);
    expect($version->page_count)->toBe(2);
    expect($version->failure_reason)->toBeNull();

    // No error alerts should be created for successful processing
    expect(Alert::where('document_version_id', $version->id)->count())->toBe(0);
});

/**
 * Alerts endpoint: In-app alerts (bell icon) and marking read.
 */
it('allows authenticated uploader to view and read in-app alerts', function (): void {
    $this->actingAs($this->user);

    $document = Document::query()->create([
        'organization_id' => $this->organization->id,
        'name' => 'Internal Regulation',
        'category' => 'regulation',
        'access_level' => 'all_staff',
        'created_by' => $this->user->id,
    ]);

    $version = DocumentVersion::query()->create([
        'document_id' => $document->id,
        'version_label' => '1.0.0',
        'storage_path' => 'documents/reg.bin',
        'original_filename' => 'reg.pdf',
        'mime_type' => 'application/pdf',
        'size_bytes' => 100,
        'sha256' => str_repeat('d', 64),
        'status' => 'processing_failed',
        'failure_reason' => 'queue_unavailable',
        'is_current' => true,
        'uploaded_by' => $this->user->id,
        'uploaded_at' => now(),
        'queued_at' => now(),
    ]);

    $alert = Alert::create([
        'user_id' => $this->user->id,
        'document_version_id' => $version->id,
        'compliance_requirement_id' => null,
        'type' => 'queue_unavailable',
        'period_days' => 0,
        'message' => 'We could not start processing "Internal Regulation". Please upload the file again or contact your administrator.',
        'due_date' => now()->toDateString(),
    ]);

    $response = $this->getJson('/api/alerts');
    $response->assertStatus(200)
        ->assertJsonCount(1, 'alerts')
        ->assertJson([
            'unread_count' => 1,
            'alerts' => [
                [
                    'id' => $alert->id,
                    'message' => 'We could not start processing "Internal Regulation". Please upload the file again or contact your administrator.',
                ],
            ],
        ]);

    $patchResponse = $this->patchJson("/api/alerts/{$alert->id}/read");
    $patchResponse->assertStatus(200);

    expect($alert->refresh()->read_at)->not->toBeNull();

    $responseAfterRead = $this->getJson('/api/alerts');
    $responseAfterRead->assertStatus(200)
        ->assertJson([
            'unread_count' => 0,
        ]);
});
