<?php

namespace App\Http\Controllers;

use App\Exceptions\AuditLogException;
use App\Models\ComplianceRequirement;
use App\Models\RequiredDocument;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Throwable;

class RequirementController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', ComplianceRequirement::class);
        $user = $request->user();
        $requirements = ComplianceRequirement::where('organization_id', $user->organization_id)->get();

        return response()->json($requirements);
    }

    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', ComplianceRequirement::class);
        $user = $request->user();

        $validated = $request->validate([
            'name' => 'required|string|max:150',
            'description' => 'required|string',
            'regulation' => 'required|string|max:150',
            'deadline' => 'required|date',
            'responsible_user_id' => 'required|exists:users,id',
            'status' => 'required|string|in:compliant,pending,at_risk,overdue',
            'notification_periods' => 'sometimes|array',
            'applicability_criteria' => 'sometimes|nullable|array',
            'applies' => 'sometimes|boolean',
        ]);

        try {
            $requirement = DB::transaction(function () use ($user, $validated) {
                return ComplianceRequirement::create([
                    'organization_id' => $user->organization_id,
                    'name' => $validated['name'],
                    'description' => $validated['description'],
                    'regulation' => $validated['regulation'],
                    'deadline' => $validated['deadline'],
                    'responsible_user_id' => $validated['responsible_user_id'],
                    'status' => $validated['status'],
                    'notification_periods' => $validated['notification_periods'] ?? [30, 15, 7, 1],
                    'applicability_criteria' => $validated['applicability_criteria'] ?? null,
                    'applies' => $validated['applies'] ?? true,
                    'created_by' => $user->id,
                ]);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        return response()->json([
            'message' => 'Requirement created successfully.',
            'requirement' => $requirement,
        ], 201);
    }

    public function show(Request $request, ComplianceRequirement $requirement): JsonResponse
    {
        $this->authorize('view', $requirement);

        return response()->json($requirement);
    }

    public function update(Request $request, ComplianceRequirement $requirement): JsonResponse
    {
        $validated = $request->validate([
            'status' => 'sometimes|string|in:compliant,pending,at_risk,overdue',
            'name' => 'sometimes|string|max:150',
            'description' => 'sometimes|string',
            'regulation' => 'sometimes|string|max:150',
            'deadline' => 'sometimes|date',
            'responsible_user_id' => 'sometimes|exists:users,id',
        ]);

        if (array_key_exists('status', $validated) && $validated['status'] !== $requirement->status) {
            $this->authorize('updateStatus', $requirement);
        } else {
            $this->authorize('update', $requirement);
        }

        try {
            DB::transaction(function () use ($requirement, $validated) {
                $requirement->update($validated);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        return response()->json([
            'message' => 'Requirement updated successfully.',
            'requirement' => $requirement->fresh(),
        ]);
    }

    public function linkDocument(Request $request, ComplianceRequirement $requirement): JsonResponse
    {
        $this->authorize('update', $requirement);
        $validated = $request->validate([
            'required_document_id' => 'required|exists:required_documents,id',
            'document_id' => 'required|exists:documents,id',
        ]);

        try {
            DB::transaction(function () use ($requirement, $validated) {
                $requiredDoc = $requirement->requiredDocuments()->findOrFail($validated['required_document_id']);
                $requiredDoc->update(['document_id' => $validated['document_id']]);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        return response()->json([
            'message' => 'Document linked successfully.',
        ]);
    }

    public function unlinkDocument(Request $request, ComplianceRequirement $requirement, RequiredDocument $requiredDocument): JsonResponse
    {
        $this->authorize('update', $requirement);
        try {
            DB::transaction(function () use ($requiredDocument) {
                $requiredDocument->update(['document_id' => null]);
            });
        } catch (Throwable $e) {
            if ($e instanceof AuditLogException) {
                throw $e;
            }

            throw new AuditLogException('The action could not be completed. Please try again.', 500, $e);
        }

        return response()->json([
            'message' => 'Document unlinked successfully.',
        ]);
    }
}
