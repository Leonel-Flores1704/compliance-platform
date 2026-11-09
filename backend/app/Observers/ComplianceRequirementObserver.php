<?php

namespace App\Observers;

use App\Exceptions\AuditLogException;
use App\Models\ComplianceRequirement;
use App\Services\AuditLogger;
use Throwable;

class ComplianceRequirementObserver
{
    public function created(ComplianceRequirement $requirement): void
    {
        try {
            AuditLogger::log('requirement.created', $requirement, [
                'name' => $requirement->name,
                'regulation' => $requirement->regulation,
                'deadline' => $requirement->deadline?->toDateString(),
                'status' => $requirement->status,
                'responsible_user_id' => $requirement->responsible_user_id,
            ]);
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }
    }

    public function updated(ComplianceRequirement $requirement): void
    {
        try {
            if ($requirement->wasChanged('status')) {
                $from = strtolower((string) ($requirement->getOriginal('status') ?? ''));
                $to = strtolower((string) ($requirement->status ?? ''));

                AuditLogger::log('requirement.status_changed', $requirement, [
                    'from' => $from,
                    'to' => $to,
                ]);
            }

            $changes = [];
            foreach ($requirement->getChanges() as $key => $newValue) {
                if (in_array($key, ['updated_at', 'status'], true)) {
                    continue;
                }
                $oldValue = $requirement->getOriginal($key);
                $changes[$key] = [
                    'old' => $oldValue,
                    'new' => $newValue,
                ];
            }

            if (! empty($changes)) {
                AuditLogger::log('requirement.updated', $requirement, [
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

