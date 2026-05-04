<?php

namespace App\Http\Controllers;

use App\Support\ThemePalette;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class SettingsController extends Controller
{
    public function index()
    {
        return view('settings.index');
    }

    public function updateAccount(Request $request)
    {
        $user = auth()->user();

        $validatedProfile = $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email,' . $user->id,
        ]);

        $wantsPasswordUpdate = $request->filled('current_password')
            || $request->filled('password')
            || $request->filled('password_confirmation');

        if ($wantsPasswordUpdate) {
            $validatedPassword = $request->validate([
                'current_password' => 'required|string',
                'password' => 'required|string|min:8',
                'password_confirmation' => 'required|string|same:password',
            ], [
                'password.min' => 'Password baru minimal 8 karakter.',
                'password_confirmation.required' => 'Konfirmasi password wajib diisi.',
                'password_confirmation.same' => 'Konfirmasi password tidak cocok.',
            ]);

            if (!Hash::check($validatedPassword['current_password'], $user->password)) {
                return back()->withErrors(['current_password' => 'Password lama tidak sesuai.']);
            }

            if (Hash::check($validatedPassword['password'], $user->password)) {
                return back()->withErrors(['password' => 'Password baru tidak boleh sama dengan password lama.']);
            }

            $user->password = Hash::make($validatedPassword['password']);
        }

        $user->name = $validatedProfile['name'];
        $user->email = $validatedProfile['email'];
        $user->save();

        $message = $wantsPasswordUpdate
            ? 'Profil dan password berhasil diperbarui!'
            : 'Profil berhasil diperbarui!';

        return back()->with('success', $message);
    }

    public function updateTheme(Request $request)
    {
        $validated = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $user = auth()->user();
        $user->update([
            'primary_color' => ThemePalette::normalize($validated['primary_color']),
        ]);

        return back()->with('success', 'Warna utama berhasil diperbarui!');
    }

}
