<?php

namespace App\Observers;

use App\Exceptions\AuditLogException;
use App\Models\Organization;
use App\Services\AuditLogger;
use Throwable;

class OrganizationObserver
{
    public function created(Organization $organization): void
    {
        try {
            AuditLogger::log('organization.created', $organization, [
                'legal_name' => $organization->legal_name,
                'organization_type' => $organization->organization_type,
                'industry' => $organization->industry,
            ]);
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }
    }

    public function updated(Organization $organization): void
    {
        try {
            $changes = [];
            foreach ($organization->getChanges() as $key => $newValue) {
                if ($key === 'updated_at') {
                    continue;
                }
                $oldValue = $organization->getOriginal($key);
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }

            if (! empty($changes)) {
                AuditLogger::log('organization.updated', $organization, [
                    'changed_fields' => $changes,
                    ...$changes,
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

