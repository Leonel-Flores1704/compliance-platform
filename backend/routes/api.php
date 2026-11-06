<?php

use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HealthController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::post('/documents/{document}/versions', [DocumentController::class, 'storeVersion']);
    Route::get('/documents/{id}/versions/{versionId}/download', [DocumentController::class, 'download']);
});

Route::get('/health', HealthController::class);

Route::post('/auth/login', function () {
    return response()->json(['message' => 'Authenticated successfully.']);
});
