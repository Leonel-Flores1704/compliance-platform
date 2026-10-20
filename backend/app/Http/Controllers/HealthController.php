<?php

namespace App\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

class HealthController extends Controller
{
    public function __invoke(): JsonResponse
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
            $response = Http::timeout(3)
                ->get(rtrim(config('services.ai.url'), '/').'/health');

            if (! $response->successful() || $response->json('status') !== 'ok') {
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
