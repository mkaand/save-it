<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\View\View;

final class PasswordController extends Controller
{
    public function forgot(): View
    {
        return view('admin.forgot-password');
    }

    public function email(Request $request): RedirectResponse
    {
        $validated = $request->validate(['email' => ['required', 'email:rfc', 'max:254']]);
        if (in_array(config('mail.default'), ['log', 'null', 'array'], true)) {
            return back()->withErrors(['email' => 'Password recovery email is temporarily unavailable.']);
        }
        if (! User::query()->where('email', $validated['email'])->where('is_admin', true)->exists()) {
            return back()->with('status', 'If an administrator account matches that address, a reset link has been sent.');
        }
        try {
            Password::sendResetLink(['email' => $validated['email']]);
        } catch (\Throwable) {
            return back()->withErrors(['email' => 'Password recovery email is temporarily unavailable.']);
        }

        return back()->with('status', 'If an administrator account matches that address, a reset link has been sent.');
    }

    public function reset(Request $request, string $token): View
    {
        return view('admin.reset-password', ['token' => $token, 'email' => $request->query('email', '')]);
    }

    public function update(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'token' => ['required', 'string'],
            'email' => ['required', 'email:rfc'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        if (! User::query()->where('email', $validated['email'])->where('is_admin', true)->exists()) {
            return back()->withErrors(['email' => 'This password reset link is invalid or has expired.']);
        }
        $status = Password::reset($validated, function (User $user, string $password): void {
            $user->forceFill(['password' => Hash::make($password), 'remember_token' => Str::random(60)])->save();
            event(new PasswordReset($user));
        });

        if ($status !== Password::PASSWORD_RESET) {
            return back()->withErrors(['email' => 'This password reset link is invalid or has expired.']);
        }

        return redirect()->route('admin.login')->with('status', 'Password reset. You may now sign in.');
    }
}
