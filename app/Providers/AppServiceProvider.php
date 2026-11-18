<?php

namespace App\Providers;

use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::define('viewReports', fn (User $user) => (bool) $user->is_admin);
        RateLimiter::for('login', function (Request $request): array {
            $email = $request->input('email');
            $identifier = is_string($email) ? mb_strtolower($email) : '';

            return [
                Limit::perMinute(5)->by($identifier.'|'.$request->ip()),
                Limit::perMinute(30)->by($request->ip()),
            ];
        });
    }
}
