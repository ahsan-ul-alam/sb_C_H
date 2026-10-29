<?php

namespace App\Providers;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Permissions;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\View;
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
        View::composer('components.layout', function ($view): void {
            $view->with('brand', BusinessSetting::current());
        });

        Notifier::register();

        $permissions = collect(Permissions::map())->flatten()->unique();
        foreach ($permissions as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
