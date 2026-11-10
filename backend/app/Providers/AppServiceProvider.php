<?php

namespace App\Providers;

use App\Events\CriteriaUpdated;
use App\Events\OrganizationSaved;
use App\Jobs\EvaluateApplicability;
use App\Services\Compliance\CriteriaValidator;
use App\Support\OrganizationCatalogs;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(CriteriaValidator::class, function ($app) {
            $catalogs = $app->make(OrganizationCatalogs::class);

            return new CriteriaValidator([
                'organization_type' => array_keys($catalogs->names('organization_types')),
                'industry' => array_keys($catalogs->names('industries')),
                'state' => array_keys($catalogs->names('mexican_states')),
                'business_activities' => array_keys($catalogs->names('business_activities')),
            ]);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }

        // CMP-17: applicability evaluation (FR-007, ADR-021).
        // Organization changes can affect every requirement, so they go to the queue.
        Event::listen(OrganizationSaved::class, fn (OrganizationSaved $event) => EvaluateApplicability::dispatch(
            $event->organization->id,
            null,
            EvaluateApplicability::REASON_ORGANIZATION,
        )->afterCommit());

        // Saving criteria evaluates one requirement right away, so the list is current when the toast appears.
        Event::listen(CriteriaUpdated::class, fn (CriteriaUpdated $event) => EvaluateApplicability::dispatchSync(
            $event->requirement->organization_id,
            $event->requirement->id,
            EvaluateApplicability::REASON_CRITERIA,
        ));
    }
}
