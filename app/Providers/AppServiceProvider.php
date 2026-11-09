<?php

namespace App\Providers;

use App\Modules\ProcessFlows\Domain\FlowScreening;
use App\Tenancy\TenantContext;
use App\Tenancy\TenantPasswordBrokerManager;
use App\Tenancy\TenantSessionHandler;
use App\Tenancy\TenantUserProvider;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(FlowScreening::class);
        $this->app->scoped(TenantContext::class);
        $this->app->extend('auth.password', fn ($manager, $app) => new TenantPasswordBrokerManager($app));
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        Session::extend('tenant-database', fn ($app) => new TenantSessionHandler(
            $app['db']->connection(config('session.connection')), config('session.table'), config('session.lifetime'), $app));
        Auth::provider('tenant-users', fn ($app, $config) => new TenantUserProvider($app['hash'], $config['model']));
    }
}
