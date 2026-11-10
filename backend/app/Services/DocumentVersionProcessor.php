<?php

namespace App\Services;

use App\Models\DocumentVersion;
use App\Services\Exceptions\AiServiceEmbeddingFailedException;
use App\Services\Exceptions\AiServiceExtractionFailedException;

/**
 * Sends a stored document version to the AI service for text extraction and
 * indexing, and records the result on the version (CMP-25 FR-003, CMP-29 NFR-019).
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
            $result = $this->client->ingest(
                $contents,
                $document->organization_id,
                $document->id,
                $version->id,
                $version->mime_type,
                $document->access_level,
                $version->original_filename,
            );
        } catch (AiServiceExtractionFailedException $e) {
            return $this->fail($version, DocumentVersion::extractionFailureMessage($e->getMessage()));
        } catch (AiServiceEmbeddingFailedException) {
            return $this->fail($version, DocumentVersion::MESSAGE_INDEXING_FAILED);
        }

        // New chunks are stored as not current; activate them when this is the current version.
        if ($version->is_current) {
            $this->client->setCurrent($document->id, $version->id);
        }

        $version->forceFill([
            'status' => DocumentVersion::STATUS_PROCESSED,
            'chunk_count' => (int) ($result['chunks'] ?? 0),
            'page_count' => (int) ($result['pages'] ?? 0),
            'processed_at' => now(),
        ])->save();

        return $version;
    }

    /**
     * A version whose processing failed is never indexed (NFR-019 acceptance check).
     */
    protected function fail(DocumentVersion $version, string $reason): DocumentVersion
    {
        $version->chunks()->delete();

        $version->forceFill([
            'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
            'failure_reason' => $reason,
            'chunk_count' => 0,
            'processed_at' => now(),
        ])->save();

        return $version;
    }
}
