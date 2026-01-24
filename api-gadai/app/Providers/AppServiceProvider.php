<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\PelunasanService;
use App\Services\NotificationService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(PelunasanService::class, function ($app) {
            return new PelunasanService();
        });
        $this->app->singleton(NotificationService::class, function ($app) {
            return new NotificationService();
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