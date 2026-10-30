<?php

use App\Http\Controllers\ComplianceRequirementController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\UserController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/users', [UserController::class, 'index']);
    Route::get('/compliance-requirements/{complianceRequirement}', [ComplianceRequirementController::class, 'show']);
});

Route::get('/health', HealthController::class);

Route::post('/auth/login', function () {
    return response()->json(['message' => 'Authenticated successfully.']);
});
