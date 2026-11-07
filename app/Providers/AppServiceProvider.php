<?php

namespace App\Providers;

use App\Models\BusinessSetting;
use App\Models\User;
use App\Services\Notifier;
use App\Support\Permissions;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\URL;
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

        if (config('app.force_https')) {
            URL::forceScheme('https');
        }

        RateLimiter::for('client-api-ip', fn (Request $request) => Limit::perMinute(120)->by('ip:'.$request->ip()));
        RateLimiter::for('client-api-request', fn (Request $request) => Limit::perMinute(20)->by('req:'.strtok((string) $request->bearerToken(), '.')));
        RateLimiter::for('client-api-verify', fn (Request $request) => Limit::perMinute(30)->by('ver:'.strtok((string) $request->bearerToken(), '.')));

        $permissions = collect(Permissions::map())->flatten()->unique();
        foreach ($permissions as $permission) {
            Gate::define($permission, fn (User $user): bool => $user->hasPermission($permission));
        }
    }
}
