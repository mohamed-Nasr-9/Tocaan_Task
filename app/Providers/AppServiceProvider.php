<?php

namespace App\Providers;

use App\Payments\GatewayResolver;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(GatewayResolver::class, fn (): GatewayResolver => new GatewayResolver(config('payments.gateways')));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
