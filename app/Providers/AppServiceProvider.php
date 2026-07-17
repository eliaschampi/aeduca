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
        // Every semantic permission is authorized through the single resolver.
        // Returning null when denied lets Laravel deny without a per-ability Gate.
        Gate::before(
            fn (AuthAccount $account, string $ability): ?bool => app(PermissionResolver::class)
                ->can($account, $ability) ? true : null,
        );
    }
}
