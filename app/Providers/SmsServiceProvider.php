<?php

namespace App\Providers;

use App\Services\Sms\CequensSmsService;
use App\Services\Sms\OtpService;
use Illuminate\Support\ServiceProvider;

/**
 * SMS Service Provider
 * 
 * Registers SMS and OTP services for dependency injection.
 */
class SmsServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register CequensSmsService as singleton
        $this->app->singleton(CequensSmsService::class, function ($app) {
            return new CequensSmsService();
        });

        // Register OtpService as singleton
        $this->app->singleton(OtpService::class, function ($app) {
            return new OtpService(
                $app->make(CequensSmsService::class)
            );
        });

        // Register aliases for convenience
        $this->app->alias(CequensSmsService::class, 'sms');
        $this->app->alias(OtpService::class, 'otp');
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Publish configuration
        $this->publishes([
            __DIR__ . '/../../config/cequens.php' => config_path('cequens.php'),
        ], 'cequens-config');
    }
}
