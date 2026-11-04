<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Http\Requests\StoreDocumentVersionRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

class DocumentController extends Controller
{
    /**
     * Store a newly uploaded document and its first version.
     */
    public function store(StoreDocumentRequest $request): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');
        $validated = $request->validated();

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $storedName = (string) Str::uuid().'.'.$extension;
        $storagePath = 'documents/'.$storedName;
        $sha256 = hash_file('sha256', $file->getRealPath());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $file->getRealPath()) : $file->getMimeType();
        if ($finfo) {
            finfo_close($finfo);
        }

        $fileStored = false;

        try {
            $result = DB::transaction(function () use ($user, $file, $validated, $storedName, $storagePath, $sha256, $detectedMime, $request, &$fileStored) {
                // 1. Persist file to the dedicated documents storage disk
                Storage::disk('documents')->putFileAs('documents', $file, $storedName);
                $fileStored = true;

                // 2. Create the document record scoped to user's organization
                $document = Document::create([
                    'organization_id' => $user->organization_id,
                    'name' => $validated['name'],
                    'category' => $validated['category'],
                    'access_level' => $validated['access_level'],
                    'is_archived' => false,
                    'created_by' => $user->id,
                ]);

                // 3. Create document version with the specified version label and status 'queued'
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

                // 4. Create successful upload audit log
                AuditLog::create([
                    'user_id' => $user->id,
                    'category' => 'functional',
                    'action' => 'document.uploaded',
                    'resource_type' => 'document',
                    'resource_id' => $document->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'document_id' => $document->id,
                        'version_id' => $version->id,
                        'version_label' => $version->version_label,
                        'original_filename' => $file->getClientOriginalName(),
                        'size_bytes' => $file->getSize(),
                        'sha256' => $sha256,
                        'category' => $document->category,
                        'access_level' => $document->access_level,
                    ],
                    'created_at' => now(),
                ]);

                return [$document, $version];
            });

            [$document, $version] = $result;

            return response()->json([
                'message' => 'Document uploaded successfully. It is now being processed.',
                'id' => $document->id,
                'document' => [
                    'id' => $document->id,
                    'organization_id' => $document->organization_id,
                    'name' => $document->name,
                    'category' => $document->category,
                    'access_level' => $document->access_level,
                    'status' => $version->status,
                    'version_label' => $version->version_label,
                    'created_at' => $document->created_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            // If storage succeeded but database transaction failed, delete the stored file
            if ($fileStored && Storage::disk('documents')->exists($storagePath)) {
                Storage::disk('documents')->delete($storagePath);
            }

            throw $e;
        }
    }

    /**
     * Store a new version for an existing document.
     */
    public function storeVersion(StoreDocumentVersionRequest $request, Document $document): JsonResponse
    {
        $user = $request->user();
        $file = $request->file('file');
        $validated = $request->validated();

        $extension = strtolower((string) $file->getClientOriginalExtension());
        $storedName = (string) Str::uuid().'.'.$extension;
        $storagePath = 'documents/'.$storedName;
        $sha256 = hash_file('sha256', $file->getRealPath());

        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        $detectedMime = $finfo ? finfo_file($finfo, $file->getRealPath()) : $file->getMimeType();
        if ($finfo) {
            finfo_close($finfo);
        }

        $fileStored = false;

        try {
            $version = DB::transaction(function () use ($user, $file, $validated, $document, $storedName, $storagePath, $sha256, $detectedMime, $request, &$fileStored) {
                // 1. Persist file to storage
                Storage::disk('documents')->putFileAs('documents', $file, $storedName);
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

                // 4. Audit log entry
                AuditLog::create([
                    'user_id' => $user->id,
                    'category' => 'functional',
                    'action' => 'document_version.uploaded',
                    'resource_type' => 'document',
                    'resource_id' => $document->id,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'metadata' => [
                        'document_id' => $document->id,
                        'version_id' => $newVersion->id,
                        'version_label' => $newVersion->version_label,
                        'original_filename' => $file->getClientOriginalName(),
                        'size_bytes' => $file->getSize(),
                        'sha256' => $sha256,
                    ],
                    'created_at' => now(),
                ]);

                return $newVersion;
            });

            return response()->json([
                'message' => 'New document version uploaded successfully.',
                'version' => [
                    'id' => $version->id,
                    'document_id' => $document->id,
                    'version_label' => $version->version_label,
                    'status' => $version->status,
                    'uploaded_at' => $version->uploaded_at,
                ],
            ], 201);
        } catch (Throwable $e) {
            if ($fileStored && Storage::disk('documents')->exists($storagePath)) {
                Storage::disk('documents')->delete($storagePath);
            }

            throw $e;
        }
    }

    /**
     * Display the specified document metadata.
     */
    public function show(Request $request, Document $document): JsonResponse
    {
        $user = $request->user();

        if ($user->cannot('view', $document)) {
            return response()->json([
                'message' => 'You do not have permission to view this document.',
            ], 403);
        }

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
