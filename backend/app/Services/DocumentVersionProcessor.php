<?php

namespace App\Services;

use App\Models\DocumentVersion;
use App\Services\Exceptions\AiServiceExtractionFailedException;

/**
 * Sends a stored document version to the AI service for text extraction and
 * indexing, and records the result on the version (CMP-25, FR-003).
 *
 * The caller (upload/queue job) is responsible for reading and decrypting the
 * stored file and passing its contents here.
 */
class DocumentVersionProcessor
{
    public function __construct(protected AiServiceClient $client) {}

    public function process(DocumentVersion $version, string $contents): DocumentVersion
    {
        $document = $version->document;

        $version->forceFill([
            'status' => DocumentVersion::STATUS_PROCESSING,
            'processing_started_at' => now(),
            'failure_reason' => null,
        ])->save();

        try {
            $this->client->ingest(
                $contents,
                $document->organization_id,
                $document->id,
                $version->id,
                $version->mime_type,
                $document->access_level,
                $version->original_filename,
            );
        } catch (AiServiceExtractionFailedException $e) {
            // A version whose text could not be extracted is never indexed.
            $version->chunks()->delete();

            $version->forceFill([
                'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
                'failure_reason' => DocumentVersion::extractionFailureMessage($e->getMessage()),
                'processed_at' => now(),
            ])->save();

            return $version;
        }

        $version->forceFill([
            'status' => DocumentVersion::STATUS_PROCESSED,
            'processed_at' => now(),
        ])->save();

        return $version;
    }
}
