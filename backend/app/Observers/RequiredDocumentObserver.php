<?php

namespace App\Observers;

use App\Exceptions\AuditLogException;
use App\Models\ComplianceRequirement;
use App\Models\RequiredDocument;
use App\Services\AuditLogger;
use Throwable;

class RequiredDocumentObserver
{
    public function created(RequiredDocument $requiredDocument): void
    {
        if ($requiredDocument->document_id !== null) {
            $this->logAction($requiredDocument, 'requirement.document_linked', $requiredDocument->document_id);
        }
    }

    public function updated(RequiredDocument $requiredDocument): void
    {
        if ($requiredDocument->wasChanged('document_id')) {
            if ($requiredDocument->document_id !== null) {
                $this->logAction($requiredDocument, 'requirement.document_linked', $requiredDocument->document_id);
            } else {
                $this->logAction($requiredDocument, 'requirement.document_unlinked', $requiredDocument->getOriginal('document_id'));
            }
        }
    }

    public function deleted(RequiredDocument $requiredDocument): void
    {
        if ($requiredDocument->document_id !== null) {
            $this->logAction($requiredDocument, 'requirement.document_unlinked', $requiredDocument->document_id);
        }
    }

    protected function logAction(RequiredDocument $requiredDocument, string $action, mixed $documentId): void
    {
        try {
            $requirement = $requiredDocument->complianceRequirement
                ?? ComplianceRequirement::find($requiredDocument->compliance_requirement_id);

            AuditLogger::log($action, $requirement, [
                'requirement_id' => $requiredDocument->compliance_requirement_id,
                'required_document_id' => $requiredDocument->id,
                'document_id' => $documentId,
            ]);
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }
    }
}

