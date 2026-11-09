<?php

namespace App\Observers;

use App\Exceptions\AuditLogException;
use App\Models\Document;
use App\Services\AuditLogger;
use Throwable;

class DocumentObserver
{
    public function created(Document $document): void
    {
        try {
            AuditLogger::log('document.uploaded', $document, [
                'document_id' => $document->id,
                'name' => $document->name,
                'category' => $document->category,
                'access_level' => $document->access_level,
            ]);
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }
    }
}
