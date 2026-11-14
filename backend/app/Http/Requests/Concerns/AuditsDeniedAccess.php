<?php

namespace App\Http\Requests\Concerns;

use App\Models\AuditLog;
use App\Models\Document;

trait AuditsDeniedAccess
{
    protected function auditAccessDenied(): void
    {
        $user = $this->user();
        $document = $this->route('document');

        AuditLog::query()->create([
            'user_id' => $user?->id,
            'category' => 'security',
            'action' => 'access.denied',
            'resource_type' => 'document',
            'resource_id' => $document instanceof Document ? $document->id : null,
            'ip_address' => $this->ip(),
            'user_agent' => $this->userAgent(),
            'metadata' => [
                'route' => $this->route()?->uri() ?? $this->path(),
                'method' => $this->method(),
                'user_role' => $user?->role?->value,
            ],
            'created_at' => now(),
        ]);
    }
}