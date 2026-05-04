@extends('layouts.app')
@section('title', 'Settings')

@section('content')
<div class="page-header mb-2">
    <div class="page-header__content">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight font-headline">Settings</h2>
        <p class="text-on-surface-variant mt-1">Kelola profil, warna utama, dan keamanan akun Anda.</p>
    </div>
</div>

@php
    $user = auth()->user();
    $currentPrimary = old('primary_color', $user->primary_color ?? \App\Support\ThemePalette::DEFAULT_PRIMARY);
@endphp

<div x-data="settingsPage(@js($currentPrimary))" :style="previewVariables()" class="settings-layout">
    <div class="settings-sidebar">
        <div class="bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in flex flex-col items-center border border-surface-container-low">
            <div class="w-20 h-20 rounded-full flex items-center justify-center font-bold text-2xl mb-4 {{ $user->isSuperAdmin() ? 'bg-primary-container text-primary' : 'bg-secondary-container text-secondary-dim' }}">
                {{ strtoupper(substr($user->name, 0, 2)) }}
            </div>
            <h3 class="font-bold text-on-surface text-lg font-headline text-center">{{ $user->name }}</h3>
            <p class="text-sm text-on-surface-variant text-center break-all">{{ $user->email }}</p>
            <div class="mt-3">
                @if($user->isSuperAdmin())
                <span class="px-4 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-primary/10 text-primary">Super Admin</span>
                @else
                <span class="px-4 py-1.5 rounded-full text-[10px] font-bold uppercase tracking-wider bg-secondary-container text-secondary-dim">Admin</span>
                @endif
            </div>
            @if($user->account)
            <div class="mt-4 w-full p-4 bg-surface-container-low rounded-xl text-center">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-1">Akun</p>
                <p class="text-sm font-semibold text-on-surface break-words">{{ $user->account->name }}</p>
            </div>
            @endif
            <div class="mt-4 w-full p-4 bg-surface-container-low rounded-xl text-center">
                <p class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-1">Member Since</p>
                <p class="text-sm font-semibold text-on-surface">{{ $user->created_at?->translatedFormat('d F Y') ?? '-' }}</p>
            </div>
        </div>
    </div>

    <div class="settings-main">
        <div class="settings-main-left">
            <div class="bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in border border-surface-container-low">
                <form method="POST" action="{{ route('settings.account') }}" class="space-y-8">
                    @csrf @method('PUT')
                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="p-2 bg-primary-container/30 rounded-lg">
                            <x-icon name="person" class="w-5 h-5 text-primary" />
                        </div>
                        <div>
                            <h3 class="font-bold text-on-surface font-headline text-lg">Update Profil</h3>
                            <p class="text-xs text-on-surface-variant">Perbarui nama dan email akun Anda</p>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <div class="settings-form-grid">
                            <div>
                                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Nama</label>
                                <input type="text" name="name" value="{{ old('name', $user->name) }}" required
                                       class="w-full bg-surface-container-high border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20" />
                                @error('name') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Email</label>
                                <input type="email" name="email" value="{{ old('email', $user->email) }}" required
                                       class="w-full bg-surface-container-high border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20" />
                                @error('email') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                <div class="my-8 border-t border-surface-container-low"></div>

                <div>
                    <div class="flex items-center gap-3 mb-6">
                        <div class="p-2 bg-error-container/20 rounded-lg">
                            <x-icon name="lock" class="w-5 h-5 text-error" />
                        </div>
                        <div>
                            <h3 class="font-bold text-on-surface font-headline text-lg">Ubah Password</h3>
                            <p class="text-xs text-on-surface-variant">Pastikan menggunakan password yang kuat</p>
                        </div>
                    </div>

                    <div class="space-y-5">
                        <div>
                            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Password Lama</label>
                            <div class="relative">
                                <input :type="showSettingsPasswords ? 'text' : 'password'" name="current_password"
                                       x-model="settingsCurrentPassword"
                                       class="w-full bg-surface-container-high border-0 rounded-xl pl-4 pr-12 py-3 text-sm focus:ring-2 focus:ring-primary/20"
                                       placeholder="Masukkan password saat ini" />
                                <button type="button"
                                        @click="toggleSettingsPasswordsVisibility()"
                                        class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-on-surface-variant transition hover:bg-surface-container hover:text-on-surface"
                                        :aria-label="showSettingsPasswords ? 'Sembunyikan password' : 'Tampilkan password'"
                                        :title="showSettingsPasswords ? 'Sembunyikan password' : 'Tampilkan password'">
                                    <x-icon x-show="!showSettingsPasswords" x-cloak name="visibility" class="w-5 h-5" />
                                    <x-icon x-show="showSettingsPasswords" x-cloak name="visibility_off" class="w-5 h-5" />
                                </button>
                            </div>
                            @error('current_password') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="settings-form-grid">
                            <div>
                                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Password Baru</label>
                                <div class="relative">
                                    <input :type="showSettingsPasswords ? 'text' : 'password'" name="password"
                                           x-model="settingsNewPassword"
                                           :aria-invalid="hasSettingsPasswordMismatch() ? 'true' : 'false'"
                                           class="w-full bg-surface-container-high border-0 rounded-xl pl-4 pr-12 py-3 text-sm focus:ring-2 focus:ring-primary/20 transition"
                                           :class="hasSettingsPasswordMismatch() ? 'ring-2 ring-error/40' : ''"
                                           placeholder="Min. 8 karakter" />
                                    <button type="button"
                                            @click="toggleSettingsPasswordsVisibility()"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-on-surface-variant transition hover:bg-surface-container hover:text-on-surface"
                                            :aria-label="showSettingsPasswords ? 'Sembunyikan password' : 'Tampilkan password'"
                                            :title="showSettingsPasswords ? 'Sembunyikan password' : 'Tampilkan password'">
                                        <x-icon x-show="!showSettingsPasswords" x-cloak name="visibility" class="w-5 h-5" />
                                        <x-icon x-show="showSettingsPasswords" x-cloak name="visibility_off" class="w-5 h-5" />
                                    </button>
                                </div>
                                @error('password') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Konfirmasi Password</label>
                                <div class="relative">
                                    <input :type="showSettingsPasswords ? 'text' : 'password'" name="password_confirmation"
                                           x-model="settingsPasswordConfirmation"
                                           @input="settingsPasswordConfirmationTouched = true"
                                           @blur="settingsPasswordConfirmationTouched = true"
                                           :aria-invalid="hasSettingsPasswordMismatch() ? 'true' : 'false'"
                                           class="w-full bg-surface-container-high border-0 rounded-xl pl-4 pr-12 py-3 text-sm focus:ring-2 focus:ring-primary/20 transition"
                                           :class="hasSettingsPasswordMismatch() ? 'ring-2 ring-error/40' : ''"
                                           placeholder="Ulangi password baru" />
                                    <button type="button"
                                            @click="toggleSettingsPasswordsVisibility()"
                                            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-on-surface-variant transition hover:bg-surface-container hover:text-on-surface"
                                            :aria-label="showSettingsPasswords ? 'Sembunyikan password' : 'Tampilkan password'"
                                            :title="showSettingsPasswords ? 'Sembunyikan password' : 'Tampilkan password'">
                                        <x-icon x-show="!showSettingsPasswords" x-cloak name="visibility" class="w-5 h-5" />
                                        <x-icon x-show="showSettingsPasswords" x-cloak name="visibility_off" class="w-5 h-5" />
                                    </button>
                                </div>
                                <p x-cloak
                                   x-show="hasSettingsPasswordMismatch()"
                                   x-text="getSettingsPasswordConfirmationError()"
                                   class="text-error text-xs mt-1"></p>
                                @error('password_confirmation') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                            </div>
                        </div>
                    </div>
                </div>

                    <div class="flex justify-end pt-2">
                        <button type="submit"
                                class="w-full sm:w-auto bg-primary text-on-primary px-8 py-3 rounded-xl text-sm font-bold shadow-lg shadow-primary/20 hover:bg-primary-dim transition-colors flex items-center justify-center gap-2">
                            <x-icon name="save" class="w-3.5 h-3.5" />
                            Simpan Semua Perubahan
                        </button>
                    </div>
                </form>
            </div>

            <div class="bg-primary/5 p-6 rounded-2xl border border-primary/10 relative overflow-hidden animate-fade-in">
            <div class="relative z-10 flex items-start gap-4">
                <div class="p-3 bg-primary/10 rounded-xl">
                    <x-icon name="info" class="w-6 h-6 text-primary" />
                </div>
                <div>
                    <h3 class="font-bold text-primary font-headline text-lg">E-REPORT</h3>
                    <p class="text-on-surface-variant text-sm mt-1">Interior Design Client Management System</p>
                    <div class="flex flex-wrap gap-4 mt-3 text-xs text-on-surface-variant">
                        <span class="flex items-center gap-1">
                            <x-icon name="code" class="w-3.5 h-3.5" /> Laravel 11
                        </span>
                        <span class="flex items-center gap-1">
                            <x-icon name="palette" class="w-3.5 h-3.5" /> Atelier Slate Design
                        </span>
                        <span class="flex items-center gap-1">
                            <x-icon name="storage" class="w-3.5 h-3.5" /> SQLite
                        </span>
                    </div>
                </div>
            </div>
            <div class="absolute -right-8 -bottom-8 w-32 h-32 bg-primary/10 rounded-full blur-2xl"></div>
        </div>
        </div>

        <div class="settings-main-right">
            <div class="bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in border border-surface-container-low">
                <div class="flex items-center gap-3 mb-6">
                    <div class="p-2 bg-primary-container/30 rounded-lg">
                        <x-icon name="palette" class="w-5 h-5 text-primary" />
                    </div>
                    <div>
                        <h3 class="font-bold text-on-surface font-headline text-lg">Tema Warna</h3>
                        <p class="text-xs text-on-surface-variant">Atur warna primary aplikasi sesuai preferensi Anda</p>
                    </div>
                </div>

                <form method="POST" action="{{ route('settings.theme') }}" class="space-y-5">
                    @csrf @method('PUT')
                    <div class="theme-swatch relative p-5">
                        <div class="theme-swatch__glow"></div>
                        <div class="relative z-10 flex h-full flex-col justify-between gap-6">
                            <div>
                                <p class="text-[10px] font-bold uppercase tracking-[0.2em] text-on-surface-variant/80">Preview</p>
                                <h4 class="mt-2 text-xl font-headline font-extrabold text-on-surface">Tema Akun Anda</h4>
                                <p class="mt-1 text-sm text-on-surface-variant">Perubahan warna akan mempengaruhi tombol, badge, highlight, dan elemen penting lain.</p>
                            </div>
                            <div class="flex flex-wrap gap-2">
                                <span class="theme-preview-chip bg-primary text-on-primary">Primary</span>
                                <span class="theme-preview-chip bg-primary-container text-primary">Container</span>
                                <span class="theme-preview-chip bg-primary/10 text-primary">Soft Accent</span>
                            </div>
                        </div>
                    </div>

                    <div class="space-y-4">
                        <div>
                            <p class="mb-3 text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">Preset Cepat</p>
                            <div class="theme-preset-grid">
                                <template x-for="color in presets" :key="color">
                                    <button type="button"
                                            @click="applyPreset(color)"
                                            :class="{ 'is-active': themeColor === color }"
                                            class="theme-preset-button">
                                        <div class="absolute inset-0" :style="`background:${color}`"></div>
                                        <span x-text="color"></span>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>

                    <div class="settings-form-grid">
                        <div>
                            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Warna Primary</label>
                            <div class="flex items-center gap-4 rounded-xl border border-surface-container bg-surface-container-low p-3 shadow-inner">
                                <input type="color"
                                       name="primary_color"
                                       x-model="themeColor"
                                       value="{{ $currentPrimary }}"
                                       class="h-12 w-12 shrink-0 cursor-pointer rounded-xl border-2 border-white bg-transparent p-0 shadow-sm">
                                <div class="min-w-0 flex-1">
                                    <p class="text-xs font-bold uppercase tracking-widest text-on-surface-variant">Kode Warna</p>
                                    <input type="text"
                                           x-model="themeColor"
                                           name="primary_color_text"
                                           readonly
                                           class="mt-1 w-full border-0 bg-transparent p-0 text-sm font-mono font-bold uppercase text-on-surface focus:ring-0">
                                </div>
                            </div>
                            @error('primary_color') <p class="text-error text-xs mt-1">{{ $message }}</p> @enderror
                        </div>
                        <div class="flex flex-col justify-end">
                            <div class="rounded-xl bg-surface-container-low p-4">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">Tips</p>
                                <p class="mt-2 text-sm text-on-surface-variant">Pilih warna yang cukup kontras agar tombol dan badge tetap nyaman dibaca di desktop maupun mobile.</p>
                            </div>
                        </div>
                    </div>

                    <div class="form-actions-responsive">
                        <button type="submit"
                                class="w-full sm:w-auto bg-primary text-on-primary px-8 py-3 rounded-xl text-sm font-bold shadow-lg shadow-primary/20 hover:bg-primary-dim transition-colors flex items-center justify-center gap-2">
                            <x-icon name="palette" class="w-3.5 h-3.5" />
                            Terapkan Warna
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>
@endsection
