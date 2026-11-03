<?php

namespace App\Http\Controllers;

use App\Support\OrganizationCatalogs;
use Illuminate\Http\JsonResponse;

class CatalogController extends Controller
{
    /**
     * GET /api/catalogs/organization: options for the organization form.
     */
    public function organization(OrganizationCatalogs $catalogs): JsonResponse
    {
        return response()->json($catalogs->forOrganizationForm());
    }

    /**
     * GET /api/catalogs/document-categories: categories for required documents (ADR-003).
     */
    public function documentCategories(OrganizationCatalogs $catalogs): JsonResponse
    {
        return response()->json(['document_categories' => $catalogs->list('document_categories')]);
    }
}
