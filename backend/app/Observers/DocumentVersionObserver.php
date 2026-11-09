<?php

namespace App\Observers;

use App\Exceptions\AuditLogException;
use App\Models\DocumentVersion;
use App\Services\AuditLogger;
use Throwable;

class DocumentVersionObserver
{
    public function created(DocumentVersion $version): void
    {
        try {
            AuditLogger::log('document_version.created', $version, [
                'document_id' => $version->document_id,
                'version_id' => $version->id,
                'version_label' => $version->version_label,
                'original_filename' => $version->original_filename,
                'mime_type' => $version->mime_type,
                'size_bytes' => $version->size_bytes,
                'sha256' => $version->sha256,
            ]);

            if ($version->is_current) {
                AuditLogger::log('document_version.set_current', $version, [
                    'document_id' => $version->document_id,
                    'version_id' => $version->id,
                    'version_label' => $version->version_label,
                ]);
            }
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }
    }

    public function updated(DocumentVersion $version): void
    {
        try {
            if ($version->wasChanged('is_current') && $version->is_current) {
                AuditLogger::log('document_version.set_current', $version, [
                    'document_id' => $version->document_id,
                    'version_id' => $version->id,
                    'version_label' => $version->version_label,
                ]);
            }

            if ($version->wasChanged('status') && $version->status === DocumentVersion::STATUS_PROCESSING_FAILED) {
                AuditLogger::log('document_version.processing_failed', $version, [
                    'document_id' => $version->document_id,
                    'version_id' => $version->id,
                    'version_label' => $version->version_label,
                    'failure_reason' => $version->failure_reason,
                ]);
            }
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }
    }
}
