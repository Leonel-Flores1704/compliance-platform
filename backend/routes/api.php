<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\ComplianceRequirementController;
use App\Http\Controllers\HealthController;
use App\Http\Controllers\OrganizationController;
use App\Http\Controllers\UserOptionsController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::middleware('auth:sanctum')->group(function () {
    Route::get('/catalogs/organization', [CatalogController::class, 'organization']);
    Route::post('/organizations', [OrganizationController::class, 'store']);
    Route::get('/organizations/{organization}', [OrganizationController::class, 'show'])->whereNumber('organization');

    Route::get('/catalogs/document-categories', [CatalogController::class, 'documentCategories']);
    Route::get('/users/options', UserOptionsController::class);
    Route::post('/compliance-requirements', [ComplianceRequirementController::class, 'store']);
    Route::get('/compliance-requirements/{complianceRequirement}', [ComplianceRequirementController::class, 'show'])->whereNumber('complianceRequirement');
});

Route::get('/health', HealthController::class);

Route::post('/auth/login', function () {
    return response()->json(['message' => 'Authenticated successfully.']);
});
