<?php

namespace App\Providers;

use App\Models\AuthAccount;
use App\Support\Authorization\PermissionResolver;
use Illuminate\Support\Facades\Gate;
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
        Gate::define(
            'dashboard.view',
            fn (AuthAccount $account): bool => app(PermissionResolver::class)
                ->can($account, 'dashboard.view'),
        );
    }
}
