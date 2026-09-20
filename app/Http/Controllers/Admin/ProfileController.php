<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\View\View;

final class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        return view('admin.profile', ['admin' => $request->user()]);
    }

    public function update(Request $request): RedirectResponse
    {
        $admin = $request->user();
        $validated = $request->validate([
            'username' => ['required', 'string', 'min:3', 'max:40', 'regex:/^[A-Za-z0-9._-]+$/', Rule::unique('users')->ignore($admin->id)],
            'email' => ['required', 'email:rfc', 'max:254', Rule::unique('users')->ignore($admin->id)],
        ]);
        $admin->update(['name' => $validated['username'], ...$validated]);

        return back()->with('status', 'Profile updated.');
    }

    public function password(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'current_password' => ['required', 'current_password'],
            'password' => ['required', 'string', 'min:12', 'confirmed'],
        ]);
        $request->user()->update([
            'password' => Hash::make($validated['password']),
            'remember_token' => Str::random(60),
        ]);
        $request->session()->regenerate();

        return back()->with('status', 'Password changed.');
    }
}
