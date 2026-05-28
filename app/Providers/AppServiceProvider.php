<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
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
        RateLimiter::for('login', function (Request $request) {
            $email = (string) $request->input('email');
            return Limit::perMinute(5)->by($email.'|'.$request->ip());
        });

        RateLimiter::for('public-table-session', function (Request $request) {
            return Limit::perMinute(12)->by($request->ip());
        });

        RateLimiter::for('public-table-action', function (Request $request) {
            $tableUuid = (string) ($request->header('X-Table-UUID') ?: $request->route('uuid') ?: 'guest');
            return Limit::perMinute(30)->by($tableUuid.'|'.$request->ip());
        });

        RateLimiter::for('mock-checkout', function (Request $request) {
            $token = (string) ($request->input('checkout_token') ?: $request->route('payment') ?: 'checkout');
            return Limit::perMinute(10)->by($token.'|'.$request->ip());
        });
    }
}
