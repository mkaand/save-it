<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class AuthController extends Controller
{
    public function create(Request $request): View|RedirectResponse
    {
        return $request->user()?->is_admin ? redirect()->route('admin.home') : view('admin.login');
    }

    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate(['login' => ['required', 'string', 'max:254'], 'password' => ['required', 'string', 'max:1024']]);
        $key = 'admin-login:'.Str::lower($validated['login']).'|'.$request->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            return back()->withErrors(['login' => 'Too many login attempts. Please try again later.'])->withInput($request->only('login'));
        }

        $field = filter_var($validated['login'], FILTER_VALIDATE_EMAIL) ? 'email' : 'username';
        $user = User::query()->where($field, $validated['login'])->where('is_admin', true)->first();
        if ($user === null || ! Auth::attempt(['id' => $user->id, 'password' => $validated['password']])) {
            RateLimiter::hit($key, 60);

            return back()->withErrors(['login' => 'The provided credentials are invalid.'])->withInput($request->only('login'));
        }

        RateLimiter::clear($key);
        $request->session()->regenerate();

        return redirect()->intended(route('admin.home'));
    }

    public function destroy(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('admin.login');
    }
}
