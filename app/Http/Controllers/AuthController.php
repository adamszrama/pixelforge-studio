<?php

namespace App\Http\Controllers;

use App\Http\Requests\LoginRequest;
use App\Http\Requests\RegisterRequest;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

final class AuthController
{
    public function show(Request $request): Response
    {
        return Inertia::render('Auth', ['registering' => $request->routeIs('register')]);
    }

    public function register(RegisterRequest $request): RedirectResponse
    {
        Auth::login(User::create($request->validated()));
        $request->session()->regenerate();

        return to_route('studio');
    }

    public function login(LoginRequest $request): RedirectResponse
    {
        $credentials = $request->validated();
        if (! Auth::attempt($credentials)) {
            throw ValidationException::withMessages(['email' => 'These credentials do not match our records.']);
        }
        $request->session()->regenerate();

        return redirect()->intended(route('studio'));
    }

    public function logout(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return to_route('login');
    }
}
