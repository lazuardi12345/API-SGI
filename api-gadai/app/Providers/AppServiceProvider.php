<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Services\PelunasanService;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // Register PelunasanService sebagai singleton
        $this->app->singleton(PelunasanService::class, function ($app) {
            return new PelunasanService();
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