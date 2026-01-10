<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\PeaceLinkService;
use App\Services\CashoutService;

class PeaceLinkServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->app->singleton(PeaceLinkService::class, function ($app) {
            return new PeaceLinkService();
        });

        $this->app->singleton(CashoutService::class, function ($app) {
            return new CashoutService($app->make(PeaceLinkService::class));
        });
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        //
    }
}
