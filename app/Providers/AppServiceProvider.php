<?php

namespace App\Providers;

use App\Services\LLMWebSearchService;
use App\Services\SearchServiceContract;
use App\Services\SerperSearchService;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(SearchServiceContract::class, function () {
            $provider = strtolower((string) config('services.search.provider', 'llm'));

            return match ($provider) {
                'serper' => new SerperSearchService(),
                'llm', 'llm_web_search' => new LLMWebSearchService(),
                default => new LLMWebSearchService(),
            };
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
