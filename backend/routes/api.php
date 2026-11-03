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
});

Route::get('/health', HealthController::class);

Route::post('/auth/login', function () {
    return response()->json(['message' => 'Authenticated successfully.']);
});
