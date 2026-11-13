<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\StoreDocumentVersionRequest;
use App\Jobs\ProcessDocumentVersion;
use App\Models\Alert;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Services\DocumentEncryptionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class DocumentController extends Controller
{
    public function __construct(protected DocumentEncryptionService $encryptionService) {}

    /**
     * Store a newly uploaded document and its first version.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $this->authorize('create', Document::class);
        $user = $request->user();
        $file = $request->file('file');
        $validated = $request->validated();

        $sha256 = hash_file('sha256', $file->getRealPath());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $file->getRealPath()) : $file->getMimeType();
        if ($finfo) {
            finfo_close($finfo);
        }

        $fileStored = false;
        $storedStoragePath = null;

        try {
            $result = DB::transaction(function () use ($user, $file, $validated, $sha256, $detectedMime, &$fileStored, &$storedStoragePath) {
                // 1. Create the document record scoped to user's organization
                $document = Document::create([
                    'organization_id' => $user->organization_id,
                    'name' => $validated['name'],
                    'category' => $validated['category'],
                    'access_level' => $validated['access_level'],
                    'is_archived' => false,
                    'created_by' => $user->id,
                ]);

                // 2. Storage path: documents/<organization_id>/<document_id>/<uuid>.bin
                $uuid = (string) Str::uuid();
                $storagePath = "documents/{$document->organization_id}/{$document->id}/{$uuid}.bin";
                $storedStoragePath = $storagePath;

                // 3. Encrypt file using streaming libsodium encryption
                $this->encryptionService->encryptFile($file->getRealPath(), $storagePath);
                $fileStored = true;

                // 4. Create document version with the specified version label and status 'queued'
                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version_label' => $validated['version'],
                    'storage_path' => $storagePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $detectedMime,
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha256,
                    'expiration_date' => $validated['expiration_date'] ?? null,
                    'status' => 'queued',
                    'is_current' => true,
                    'uploaded_by' => $user->id,
                    'uploaded_at' => now(),
                    'queued_at' => now(),
                ]);

                return [$document, $version];
            });

            [$document, $version] = $result;

            $version->forceFill(['queued_at' => now()])->save();

            try {
                ProcessDocumentVersion::dispatch($version)
                    ->onQueue('documents')
                    ->afterCommit();
            } catch (Throwable $e) {
                $version->forceFill([
                    'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
                    'failure_reason' => 'queue_unavailable',
                ])->save();

                Alert::create([
                    'user_id' => $user->id,
                    'document_version_id' => $version->id,
                    'compliance_requirement_id' => null,
                    'type' => 'queue_unavailable',
                    'period_days' => 0,
                    'message' => "We could not start processing \"{$document->name}\". Please upload the file again or contact your administrator.",
                    'due_date' => now()->toDateString(),
                ]);
            }

            return response()->json([
                'message' => 'Document uploaded successfully. It is now being processed.',
                'id' => $document->id,
                'document' => [
                    'id' => $document->id,
                    'organization_id' => $document->organization_id,
                    'name' => $document->name,
                    'category' => $document->category,
                    'access_level' => $document->access_level,
                    'status' => $version->refresh()->status,
                    'version_label' => $version->version_label,
                    'created_at' => $document->created_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            if ($fileStored && $storedStoragePath) {
                $this->encryptionService->delete($storedStoragePath);
            }

            throw $e;
        }
    }

    /**
     * Store a new version for an existing document.
     */
    public function storeVersion(StoreDocumentVersionRequest $request, Document $document): JsonResponse
    {
        $this->authorize('createVersion', $document);
        $user = $request->user();
        $file = $request->file('file');
        $validated = $request->validated();

        $sha256 = hash_file('sha256', $file->getRealPath());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $file->getRealPath()) : $file->getMimeType();
        if ($finfo) {
            finfo_close($finfo);
        }

        $fileStored = false;
        $storedStoragePath = null;

        try {
            $version = DB::transaction(function () use ($user, $file, $validated, $document, $sha256, $detectedMime, &$fileStored, &$storedStoragePath) {
                $uuid = (string) Str::uuid();
                $storagePath = "documents/{$document->organization_id}/{$document->id}/{$uuid}.bin";
                $storedStoragePath = $storagePath;

                // 1. Encrypt file using streaming libsodium encryption
                $this->encryptionService->encryptFile($file->getRealPath(), $storagePath);
                $fileStored = true;

                // 2. Clear current status from existing current version
                DocumentVersion::where('document_id', $document->id)
                    ->where('is_current', true)
                    ->update(['is_current' => false]);

                // 3. Create new document version
                $newVersion = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version_label' => $validated['version'],
                    'storage_path' => $storagePath,
                    'original_filename' => $file->getClientOriginalName(),
                    'mime_type' => $detectedMime,
                    'size_bytes' => $file->getSize(),
                    'sha256' => $sha256,
                    'expiration_date' => $validated['expiration_date'] ?? null,
                    'status' => 'queued',
                    'is_current' => true,
                    'uploaded_by' => $user->id,
                    'uploaded_at' => now(),
                    'queued_at' => now(),
                ]);

                return $newVersion;
            });

            $version->forceFill(['queued_at' => now()])->save();

            try {
                ProcessDocumentVersion::dispatch($version)
                    ->onQueue('documents')
                    ->afterCommit();
            } catch (Throwable $e) {
                $version->forceFill([
                    'status' => DocumentVersion::STATUS_PROCESSING_FAILED,
                    'failure_reason' => 'queue_unavailable',
                ])->save();

                Alert::create([
                    'user_id' => $user->id,
                    'document_version_id' => $version->id,
                    'compliance_requirement_id' => null,
                    'type' => 'queue_unavailable',
                    'period_days' => 0,
                    'message' => "We could not start processing \"{$document->name}\". Please upload the file again or contact your administrator.",
                    'due_date' => now()->toDateString(),
                ]);
            }

            return response()->json([
                'message' => 'New document version uploaded successfully.',
                'version' => [
                    'id' => $version->id,
                    'document_id' => $document->id,
                    'version_label' => $version->version_label,
                    'status' => $version->refresh()->status,
                    'uploaded_at' => $version->uploaded_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            if ($fileStored && $storedStoragePath) {
                $this->encryptionService->delete($storedStoragePath);
            }

            throw $e;
        }
    }

    /**
     * Download an authorized document version stream after authorization and verification.
     */
    public function download(Request $request, mixed $id, mixed $versionId): JsonResponse|StreamedResponse
    {
        $document = Document::find($id);

        if (! $document) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        $user = $request->user();

        if ($user->cannot('download', $document)) {
            AuditLog::query()->create([
                'user_id' => $user->id,
                'category' => 'security',
                'action' => 'document.download_denied',
                'resource_type' => 'document',
                'resource_id' => $document->id,
                'ip_address' => $request->ip(),
                'user_agent' => $request->userAgent(),
                'metadata' => [
                    'document_id' => $document->id,
                    'version_id' => is_numeric($versionId) ? (int) $versionId : $versionId,
                    'access_level' => $document->access_level,
                    'user_role' => $user->role->value,
                ],
                'created_at' => now(),
            ]);
        }

        $this->authorize('download', $document);

        $version = $document->versions()->find($versionId);

        if (! $version) {
            return response()->json([
                'message' => 'Document not found.',
            ], 404);
        }

        try {
            $stream = $this->encryptionService->decryptAndVerify($version);
        } catch (Throwable) {
            return response()->json([
                'message' => 'The file could not be retrieved. Please contact your administrator.',
            ], 500);
        }

        return response()->stream(function () use ($stream) {
            while (! feof($stream)) {
                $buffer = fread($stream, 65536);
                if ($buffer !== false && $buffer !== '') {
                    echo $buffer;
                    flush();
                }
            }
            fclose($stream);
        }, 200, [
            'Content-Type' => $version->mime_type,
            'Content-Disposition' => 'attachment; filename="'.$version->original_filename.'"',
            'X-Content-Type-Options' => 'nosniff',
        ]);
    }

    /**
     * Display the specified document metadata.
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        $this->authorize('view', $document);

        $currentVersion = $document->versions()
            ->where('is_current', true)
            ->with('uploader')
            ->first() ?? $document->versions()->latest('uploaded_at')->with('uploader')->first();

        $categoryName = StoreDocumentRequest::CATEGORY_NAMES[$document->category] ?? ucfirst(str_replace('_', ' ', $document->category));
        $visibilityName = $document->access_level === 'all_staff' ? 'All staff' : 'Compliance only';

        $expirationFormatted = $currentVersion?->expiration_date
            ? $currentVersion->expiration_date->format('d/m/Y')
            : 'No expiration';

        $uploadedAtFormatted = $currentVersion?->uploaded_at
            ? $currentVersion->uploaded_at->format('d/m/Y H:i')
            : null;

        $uploaderName = $currentVersion?->uploader?->name ?? 'Unknown';

        return response()->json([
            'id' => $document->id,
            'name' => $document->name,
            'category' => $document->category,
            'category_name' => $categoryName,
            'access_level' => $document->access_level,
            'visibility' => $visibilityName,
            'is_archived' => $document->is_archived,
            'current_version' => $currentVersion?->version_label,
            'expiration_date' => $currentVersion?->expiration_date?->format('Y-m-d'),
            'expiration_date_formatted' => $expirationFormatted,
            'uploaded_at' => $currentVersion?->uploaded_at?->toISOString(),
            'upload_date' => $uploadedAtFormatted,
            'uploaded_by' => $uploaderName,
            'processing_status' => $currentVersion?->status ?? 'queued',
            'status' => $currentVersion?->status ?? 'queued',
            'document' => [
                'id' => $document->id,
                'name' => $document->name,
                'category' => $document->category,
                'category_name' => $categoryName,
                'access_level' => $document->access_level,
                'visibility' => $visibilityName,
                'current_version' => $currentVersion?->version_label,
                'expiration_date' => $expirationFormatted,
                'uploaded_by' => $uploaderName,
                'upload_date' => $uploadedAtFormatted,
                'processing_status' => $currentVersion?->status ?? 'queued',
            ],
        ]);
    }
}
