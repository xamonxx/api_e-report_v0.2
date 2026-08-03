<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Support\ThemePalette;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

class SettingsController extends Controller
{
    /**
     * POST /api/v1/settings/profile
     */
    public function updateProfile(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validatedProfile = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[^\<\>]+$/'],
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
                return response()->json([
                    'message' => 'Password lama tidak sesuai.',
                    'errors' => ['current_password' => ['Password lama tidak sesuai.']],
                ], 422);
            }

            if (Hash::check($validatedPassword['password'], $user->password)) {
                return response()->json([
                    'message' => 'Password baru tidak boleh sama dengan password lama.',
                    'errors' => ['password' => ['Password baru tidak boleh sama dengan password lama.']],
                ], 422);
            }

            $user->password = Hash::make($validatedPassword['password']);
        }

        $user->name = $validatedProfile['name'];
        $user->email = $validatedProfile['email'];
        $user->save();

        $message = $wantsPasswordUpdate
            ? 'Profil dan password berhasil diperbarui!'
            : 'Profil berhasil diperbarui!';

        return response()->json([
            'message' => $message,
            'user' => $user,
        ]);
    }

    /**
     * POST /api/v1/settings/avatar
     *
     * Terima file `avatar` (ganti/unggah) ATAU flag `remove_avatar=1` (hapus).
     * Mengembalikan path mentah — frontend memprefiks base URL + /storage,
     * konsisten dengan account.logo. SVG sengaja ditolak (risiko stored-XSS).
     */
    public function updateAvatar(Request $request): JsonResponse
    {
        $user = auth()->user();

        $validated = $request->validate([
            'avatar' => [
                'nullable',
                'image',
                'mimes:jpeg,png,jpg,gif,webp',
                'max:2048',
                'dimensions:max_width=2000,max_height=2000',
            ],
            'remove_avatar' => ['nullable', 'boolean'],
        ], [
            'avatar.image' => 'Berkas harus berupa gambar.',
            'avatar.mimes' => 'Format foto harus JPG, PNG, GIF, atau WEBP.',
            'avatar.max' => 'Ukuran foto maksimal 2 MB.',
            'avatar.dimensions' => 'Dimensi foto maksimal 2000 x 2000 piksel.',
        ]);

        if ($request->boolean('remove_avatar')) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            $user->avatar_path = null;
        } elseif ($request->hasFile('avatar') && $request->file('avatar')->isValid()) {
            if ($user->avatar_path) {
                Storage::disk('public')->delete($user->avatar_path);
            }
            // Nama file diacak oleh Laravel (hashName) — bukan dari input klien.
            $user->avatar_path = $request->file('avatar')->store('avatars', 'public');
        } else {
            return response()->json([
                'message' => 'Tidak ada foto untuk diperbarui.',
                'errors' => ['avatar' => ['Pilih foto terlebih dahulu.']],
            ], 422);
        }

        $user->save();

        return response()->json([
            'message' => $request->boolean('remove_avatar')
                ? 'Foto profil berhasil dihapus.'
                : 'Foto profil berhasil diperbarui.',
            'avatar' => $user->avatar_path,
        ]);
    }

    /**
     * POST /api/v1/settings/theme
     */
    public function updateTheme(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'primary_color' => ['required', 'regex:/^#[0-9A-Fa-f]{6}$/'],
        ]);

        $user = auth()->user();
        $user->update([
            'primary_color' => ThemePalette::normalize($validated['primary_color']),
        ]);

        return response()->json([
            'message' => 'Warna utama berhasil diperbarui!',
            'primary_color' => $user->primary_color,
        ]);
    }
}
