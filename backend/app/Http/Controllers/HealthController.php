<?php

namespace App\Http\Controllers;

use App\Services\AiServiceClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class HealthController extends Controller
{
    public function __invoke(AiServiceClient $aiService): JsonResponse
    {
        try {
            DB::connection()->getPdo();
            DB::select('select 1');
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'error',
                'database' => 'unreachable',
            ], 503);
        }

        try {
            $health = $aiService->health();

            if (($health['status'] ?? null) !== 'ok') {
                throw new \RuntimeException('AI service unhealthy');
            }
        } catch (\Throwable $e) {
            return response()->json([
                'status' => 'degraded',
                'database' => 'ok',
                'ai_service' => 'unreachable',
            ], 503);
        }

        return response()->json([
            'status' => 'ok',
            'database' => 'ok',
            'ai_service' => 'ok',
        ]);
    }
}
