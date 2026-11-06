<?php

namespace App\Providers;

use App\Models\Document;
use App\Policies\DocumentPolicy;
use App\Support\AssistantMessages;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
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

        // CMP-33: 20 questions per minute per user.
        RateLimiter::for('assistant-questions', fn (Request $request) => Limit::perMinute(20)
            ->by('assistant:'.$request->user()?->id)
            ->response(fn (Request $request, array $headers) => response()->json(
                ['message' => AssistantMessages::TOO_MANY_QUESTIONS],
                429,
                $headers,
            )));

        if ($this->app->environment('production')) {
            URL::forceScheme('https');
        }
    }
}
