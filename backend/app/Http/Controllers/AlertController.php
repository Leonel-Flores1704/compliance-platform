<?php

namespace App\Http\Controllers;

use App\Models\Alert;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AlertController extends Controller
{
    /**
     * Display a listing of alerts for the authenticated user.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();

        $alerts = Alert::where('user_id', $user->id)
            ->with(['documentVersion.document', 'complianceRequirement'])
            ->orderByDesc('created_at')
            ->get();

        return response()->json([
            'alerts' => $alerts,
            'unread_count' => $alerts->whereNull('read_at')->count(),
        ]);
    }

    /**
     * Mark an alert as read.
     */
    public function markAsRead(Request $request, Alert $alert): JsonResponse
    {
        if ($alert->user_id !== $request->user()->id) {
            return response()->json([
                'message' => 'You do not have permission to modify this alert.',
            ], 403);
        }

        $alert->update(['read_at' => now()]);

        return response()->json([
            'message' => 'Alert marked as read.',
            'alert' => $alert,
        ]);
    }
}
