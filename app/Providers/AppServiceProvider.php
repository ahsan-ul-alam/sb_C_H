<?php

namespace App\Providers;

use App\Models\BusinessSetting;
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
    }
}
