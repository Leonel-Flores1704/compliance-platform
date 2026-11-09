<?php

namespace App\Providers;

use App\Models\ComplianceRequirement;
use App\Models\Document;
use App\Models\DocumentVersion;
use App\Models\Organization;
use App\Models\RequiredDocument;
use App\Observers\ComplianceRequirementObserver;
use App\Observers\DocumentObserver;
use App\Observers\DocumentVersionObserver;
use App\Observers\OrganizationObserver;
use App\Observers\RequiredDocumentObserver;
use App\Policies\DocumentPolicy;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Gate::policy(Document::class, DocumentPolicy::class);

        ComplianceRequirement::observe(ComplianceRequirementObserver::class);
        Organization::observe(OrganizationObserver::class);
        Document::observe(DocumentObserver::class);
        DocumentVersion::observe(DocumentVersionObserver::class);
        RequiredDocument::observe(RequiredDocumentObserver::class);

        ComplianceRequirement::observe(ComplianceRequirementObserver::class);
        Organization::observe(OrganizationObserver::class);
        Document::observe(DocumentObserver::class);
        DocumentVersion::observe(DocumentVersionObserver::class);
        RequiredDocument::observe(RequiredDocumentObserver::class);

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
