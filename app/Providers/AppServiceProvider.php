<?php

namespace App\Providers;

use Illuminate\Pagination\Paginator;
use Illuminate\Support\Facades\View;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        Paginator::useBootstrapFive();
        View::composer('layouts.app', function ($view): void {
            if (! auth()->check()) {
                return;
            }

            $alerts = auth()->user()->appAlerts();
            $view->with('unreadAlertCount', (clone $alerts)->whereNull('read_at')->whereNull('resolved_at')->count());
            $view->with('recentAlerts', (clone $alerts)->whereNull('resolved_at')->latest()->limit(5)->get());
        });
    }
}
