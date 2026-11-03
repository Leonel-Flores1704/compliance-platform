<?php

namespace App\Http\Controllers;

use App\Http\Requests\StoreDocumentRequest;
use App\Models\AuditLog;
use App\Models\Document;
use App\Models\DocumentVersion;
use Illuminate\Http\JsonResponse;
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

                // 3. Create document version 1 with status 'queued'
                $version = DocumentVersion::create([
                    'document_id' => $document->id,
                    'version_label' => 'v1',
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
                        'version_label' => 'v1',
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
}
