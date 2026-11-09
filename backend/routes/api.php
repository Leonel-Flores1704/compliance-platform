<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\RequirementController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::post('/auth/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/documents', [DocumentController::class, 'store']);
    Route::get('/documents/{document}', [DocumentController::class, 'show']);
    Route::post('/documents/{document}/versions', [DocumentController::class, 'storeVersion']);
    Route::get('/documents/{id}/versions/{versionId}/download', [DocumentController::class, 'download']);

    Route::get('/requirements', [RequirementController::class, 'index']);
    Route::post('/requirements', [RequirementController::class, 'store']);
    Route::get('/requirements/{requirement}', [RequirementController::class, 'show']);
    Route::patch('/requirements/{requirement}', [RequirementController::class, 'update']);
    Route::put('/requirements/{requirement}', [RequirementController::class, 'update']);
    Route::post('/requirements/{requirement}/documents', [RequirementController::class, 'linkDocument']);
    Route::delete('/requirements/{requirement}/documents/{requiredDocument}', [RequirementController::class, 'unlinkDocument']);

    Route::post('/auth/logout', [AuthController::class, 'logout']);
    Route::get('/auth/me', [AuthController::class, 'me']);
});

Route::get('/health', HealthController::class);
