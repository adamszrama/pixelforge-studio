<?php

namespace App\Http\Middleware;

use App\Domain\Billing\Money;
use Illuminate\Http\Request;
use Inertia\Middleware;

final class HandleInertiaRequests extends Middleware
{
    protected $rootView = 'app';

    public function share(Request $request): array
    {
        return [...parent::share($request),
            'auth' => fn () => $request->user() ? [
                'name' => $request->user()->name,
                'is_admin' => (bool) $request->user()->is_admin,
                'balance' => Money::usd($request->user()->balance),
                'reserved' => Money::usd($request->user()->reserved),
                'available' => Money::usd($request->user()->balance - $request->user()->reserved),
            ] : null,
            'flash' => ['success' => fn () => $request->session()->get('success')],
        ];
    }
}
