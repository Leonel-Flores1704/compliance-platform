<?php

namespace App\Services;

use App\Exceptions\AuditLogException;
use App\Models\AuditLog;
use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\RequiredDocument;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;
use Mockery;
use Throwable;

class AuditLogger
{
    /**
     * Disallowed keys in metadata that should never be stored.
     */
    protected const DISALLOWED_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'file',
        'file_content',
        'file_contents',
        'contents',
        'content',
        'document_content',
        'full_document',
        'raw_file',
        'raw_content',
        'stream',
    ];

    /**
     * Static entrypoint for logging a functional audit event.
     */
    public static function log(string $action, ?Model $resource = null, array $metadata = []): AuditLog
    {
        if (app()->bound(self::class)) {
            $instance = app(self::class);
            if ($instance instanceof Mockery\MockInterface) {
                try {
                    return $instance->log($action, $resource, $metadata);
                } catch (\BadMethodCallException) {
                    return $instance->record($action, $resource, $metadata);
                }
            }

            return $instance->record($action, $resource, $metadata);
        }

        return (new self)->record($action, $resource, $metadata);
    }

    /**
     * Instance method to record an audit log.
     */
    public function record(string $action, ?Model $resource = null, array $metadata = []): AuditLog
    {
        try {
            $user = request()?->user() ?? Auth::user();
            $userId = $user?->id
                ?? (Auth::check() ? Auth::id() : null)
                ?? $metadata['user_id']
                ?? $resource?->getAttribute('created_by')
                ?? $resource?->getAttribute('uploaded_by')
                ?? $resource?->getAttribute('responsible_user_id')
                ?? $resource?->getAttribute('user_id');

            unset($metadata['user_id']);

            $sanitizedMetadata = $this->sanitizeMetadata($metadata);

            $resourceType = $metadata['resource_type'] ?? $this->resolveResourceType($resource, $action);
            unset($metadata['resource_type']);

            $resourceId = $metadata['resource_id'] ?? $resource?->getKey();
            unset($metadata['resource_id']);

            return AuditLog::create([
                'user_id' => $userId,
                'category' => 'functional',
                'action' => $action,
                'resource_type' => $resourceType,
                'resource_id' => $resourceId,
                'ip_address' => request()?->ip(),
                'user_agent' => request()?->userAgent() ? substr(request()->userAgent(), 0, 255) : null,
                'metadata' => $sanitizedMetadata,
                'created_at' => now('UTC'),
            ]);
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }
    }

    /**
     * Resolve resource type name from resource model or action.
     */
    protected function resolveResourceType(?Model $resource, string $action = ''): ?string
    {
        if ($resource instanceof Document) {
            return 'document';
        }

        if ($resource instanceof DocumentVersion) {
            return 'document_version';
        }

        if ($resource instanceof ComplianceRequirement) {
            return 'requirement';
        }

        if ($resource instanceof Organization) {
            return 'organization';
        }

        if ($resource instanceof RequiredDocument) {
            return 'requirement';
        }

        if ($resource instanceof User) {
            return 'user';
        }

        if ($resource) {
            return strtolower(class_basename($resource));
        }

        if (str_starts_with($action, 'document_version.')) {
            return 'document_version';
        }

        if (str_starts_with($action, 'document.')) {
            return 'document';
        }

        if (str_starts_with($action, 'requirement.')) {
            return 'requirement';
        }

        if (str_starts_with($action, 'organization.')) {
            return 'organization';
        }

        return null;
    }

    /**
     * Sanitize metadata to never store passwords, file contents, or full documents.
     */
    public function sanitizeMetadata(array $metadata): array
    {
        $sanitized = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::DISALLOWED_KEYS, true)) {
                continue;
            }

            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeMetadata($value);
            } elseif (is_string($value)) {
                // Redact binary content, document headers, or oversized strings
                if (
                    str_contains($value, "\0") ||
                    str_starts_with($value, '%PDF-') ||
                    str_starts_with($value, "PK\x03\x04") ||
                    strlen($value) > 10000
                ) {
                    continue;
                }
                $sanitized[$key] = $value;
            } else {
                $sanitized[$key] = $value;
            }
        }

        return $sanitized;
    }

    /**
     * Display a timestamp in America/Mexico_City timezone.
     */
    public static function displayTimestamp(mixed $timestamp, string $format = 'Y-m-d H:i:s'): ?string
    {
        if (! $timestamp) {
            return null;
        }

        return Carbon::parse($timestamp)->timezone('America/Mexico_City')->format($format);
    }

    /**
     * Static forwarding to container instance, supporting Mockery facade-style calls.
     */
    public static function __callStatic(string $method, array $args)
    {
        if ($method === 'shouldReceive') {
            $instance = app()->bound(static::class) && app(static::class) instanceof Mockery\MockInterface
                ? app(static::class)
                : Mockery::mock(static::class);

            app()->instance(static::class, $instance);

            return $instance->shouldReceive(...$args);
        }

        return app(static::class)->$method(...$args);
    }
}
