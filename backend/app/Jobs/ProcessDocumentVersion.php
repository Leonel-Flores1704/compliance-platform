<?php

namespace App\Jobs;

use App\Models\Alert;
use App\Models\DocumentVersion;
use App\Services\DocumentEncryptionService;
use App\Services\DocumentVersionProcessor;
use App\Services\Exceptions\AiServiceEmbeddingFailedException;
use App\Services\Exceptions\AiServiceExtractionFailedException;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use RuntimeException;
use Throwable;

class ProcessDocumentVersion implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying the job.
     *
     * @var array<int, int>
     */
    public array $backoff = [30, 120, 300];

    /**
     * The number of seconds the job can run before timing out.
     */
    public int $timeout = 300;

    public function __construct(public DocumentVersion $version)
    {
        $this->queue = 'documents';
    }

    /**
     * Execute the job.
     */
    public function handle(
        DocumentEncryptionService $encryptionService,
        DocumentVersionProcessor $processor
    ): void {
        $this->version->refresh();

        // 1. Set status 'processing' + processing_started_at
        $this->version->forceFill([
            'status' => DocumentVersion::STATUS_PROCESSING,
            'processing_started_at' => now(),
            'failure_reason' => null,
        ])->save();

        // 2. Decrypt file (CMP-22)
        $stream = $encryptionService->decryptAndVerify($this->version);
        $contents = stream_get_contents($stream);
        if (is_resource($stream)) {
            fclose($stream);
        }

        // 3. Call FastAPI ingest (CMP-07) & 4. On success status 'processed' + processed_at and make version current (CMP-23)
        $result = $processor->process($this->version, $contents);

        if ($result->status === DocumentVersion::STATUS_PROCESSING_FAILED) {
            throw new RuntimeException($result->failure_reason ?: 'Processing failed.');
        }
    }

    /**
     * Handle a job failure after all attempts have been exhausted.
     */
    public function failed(?Throwable $exception): void
    {
        $this->version->refresh();

        $reason = $this->version->failure_reason;
        if (! $reason && $exception) {
            if ($exception instanceof AiServiceExtractionFailedException) {
                $reason = DocumentVersion::extractionFailureMessage($exception->getMessage());
            } elseif ($exception instanceof AiServiceEmbeddingFailedException) {
                $reason = DocumentVersion::MESSAGE_INDEXING_FAILED;
            } else {
                $reason = $exception->getMessage() ?: 'Processing failed.';
            }
        }

        if (! $reason) {
            $reason = 'Processing failed.';
        }

        // Clean up any partial chunks so failed version is not indexed (NFR-019)
        $this->version->chunks()->delete();

        $this->version->forceFill([
            'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
            'failure_reason' => $reason,
            'chunk_count' => 0,
            'processed_at' => now(),
        ])->save();

        $document = $this->version->document;
        $documentName = $document?->name ?? 'document';
        $trimmedReason = rtrim($reason, '.');

        Alert::create([
            'user_id' => $this->version->uploaded_by,
            'document_version_id' => $this->version->id,
            'compliance_requirement_id' => null,
            'type' => 'processing_failed',
            'period_days' => 0,
            'message' => "Processing failed for \"{$documentName}\" (version {$this->version->version_label}): {$trimmedReason}.",
            'due_date' => now()->toDateString(),
        ]);
    }
}
