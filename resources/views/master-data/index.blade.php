@extends('layouts.app')
@section('title', 'Master Data')

@section('content')
{{-- Page Header --}}
<div class="flex flex-col sm:flex-row sm:items-end justify-between gap-6 mb-8 px-1">
    <div>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-on-surface tracking-tight font-headline">Master Data</h2>
        <p class="text-sm sm:text-base text-on-surface-variant mt-1">Kelola kategori kebutuhan, status, dan pengguna sistem.</p>
    </div>
</div>

{{-- Tab Navigation --}}
@php
    $userFormContext = session('user_form_context');
    $createUserErrors = $errors->getBag('createUser');
    $editUserErrors = $errors->getBag('editUser');
    $isCreateUserContext = $userFormContext === 'create';
    $isEditUserContext = $userFormContext === 'edit';
@endphp
<div x-data="masterDataPage({
        initialTab: @js(($isCreateUserContext || $isEditUserContext) ? 'users' : $tab),
        showEditUserModal: {{ $isEditUserContext ? 'true' : 'false' }},
        createUserRole: @js($isCreateUserContext ? old('role', 'admin') : 'admin'),
        editUser: {
            id: @js($isEditUserContext ? old('edit_user_id', '') : ''),
            name: @js($isEditUserContext ? old('name', '') : ''),
            email: @js($isEditUserContext ? old('email', '') : ''),
            role: @js($isEditUserContext ? old('role', 'admin') : 'admin'),
            account_id: @js($isEditUserContext ? (string) old('account_id', '') : ''),
        }
    })" x-init="init()">
    <div class="grid grid-cols-3 sm:flex overflow-x-auto scrollbar-none bg-surface-container-lowest p-1.5 rounded-xl shadow-sm w-full sm:w-fit mb-8 gap-1 scroll-px-2 no-print">
        <button @click="activeTab = 'categories'"
           :class="activeTab === 'categories' ? 'bg-primary text-on-primary shadow-lg shadow-primary/20' : 'text-on-surface-variant hover:bg-surface-container-low'"
           class="min-w-0 whitespace-nowrap px-3 sm:px-6 py-2.5 rounded-lg text-xs sm:text-sm font-bold transition-all duration-300 inline-flex items-center justify-center gap-2">
            <x-icon name="folder_shared" class="w-3.5 h-3.5" />
            <span>Kategori</span>
        </button>
        <button @click="activeTab = 'statuses'"
           :class="activeTab === 'statuses' ? 'bg-primary text-on-primary shadow-lg shadow-primary/20' : 'text-on-surface-variant hover:bg-surface-container-low'"
           class="min-w-0 whitespace-nowrap px-3 sm:px-6 py-2.5 rounded-lg text-xs sm:text-sm font-bold transition-all duration-300 inline-flex items-center justify-center gap-2">
            <x-icon name="label" class="w-3.5 h-3.5" />
            <span>Status</span>
        </button>
        <button @click="activeTab = 'users'"
           :class="activeTab === 'users' ? 'bg-primary text-on-primary shadow-lg shadow-primary/20' : 'text-on-surface-variant hover:bg-surface-container-low'"
           class="min-w-0 whitespace-nowrap px-3 sm:px-6 py-2.5 rounded-lg text-xs sm:text-sm font-bold transition-all duration-300 inline-flex items-center justify-center gap-2">
            <x-icon name="group" class="w-3.5 h-3.5" />
            <span>Users</span>
        </button>
    </div>

{{-- TAB: Kategori Kebutuhan --}}
<div x-cloak x-show="activeTab === 'categories'" x-transition.opacity.duration.300ms class="grid grid-cols-1 xl:grid-cols-12 gap-6 sm:gap-8">
    {{-- Add Form --}}
    <div class="xl:col-span-4 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm border border-surface-container-low h-fit">
        <h3 class="font-extrabold text-on-surface font-headline text-lg mb-6 flex items-center gap-2">
            <x-icon name="add_circle" class="w-5 h-5 text-primary" /> Tambah Kategori
        </h3>
        <form method="POST" action="{{ route('master-data.categories.store') }}" class="space-y-6">
            @csrf
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Kategori</label>
                <input type="text" name="name" required
                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold"
                       placeholder="Contoh: Kitchen Set" />
                @error('name') <p class="text-error text-xs mt-1 px-1 font-medium">{{ $message }}</p> @enderror
            </div>
            <button type="submit"
                    class="w-full bg-primary text-on-primary py-3.5 rounded-xl text-sm font-bold shadow-xl shadow-primary/20 hover:bg-primary-dim transition-all active:scale-[0.98]">
                <span>Simpan Kategori</span>
            </button>
        </form>
    </div>

    {{-- Categories Table --}}
    <div class="xl:col-span-8 bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden border border-surface-container-low flex flex-col">
        <div class="px-6 sm:px-8 py-6 bg-surface-container-low/45 border-b border-surface-container-low">
            <h3 class="font-bold text-on-surface font-headline">Daftar Kategori Kebutuhan</h3>
            <p class="text-xs text-on-surface-variant mt-1">{{ $categories->total() }} kategori terdaftar dalam sistem</p>
        </div>
        <div class="table-scroll-mobile overflow-x-auto scrollbar-thin scrollbar-thumb-surface-container">
            <table class="w-full min-w-[500px] text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-surface-container-low/50">
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">#</th>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Nama Kategori</th>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-right leading-none">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-low">
                    @forelse($categories as $index => $cat)
                    <tr class="hover:bg-surface-container-low/30 transition-colors group">
                        <td class="px-6 sm:px-8 py-4 text-sm font-bold text-on-surface-variant/40">{{ $index + 1 }}</td>
                        <td class="px-6 sm:px-8 py-4">
                            <span class="cat-display-{{ $cat->id }} font-bold text-sm text-on-surface">{{ $cat->name }}</span>
                            <form method="POST" action="{{ route('master-data.categories.update', $cat) }}"
                                  class="cat-edit-{{ $cat->id }} hidden flex items-center gap-2">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $cat->name }}"
                                       class="bg-surface-container-low border border-primary/30 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-primary/20 w-full font-bold shadow-sm"/>
                                <button type="submit" class="text-primary hover:text-primary-dim transition-colors p-1" title="Simpan">
                                    <x-icon name="done" class="w-[18px] h-[18px]" />
                                </button>
                                <button type="button" onclick="toggleCatEdit({{ $cat->id }})" class="text-on-surface-variant hover:text-error transition-colors p-1" title="Batal">
                                    <x-icon name="close" class="w-[18px] h-[18px]" />
                                </button>
                            </form>
                        </td>
                        <td class="px-6 sm:px-8 py-4 text-right">
                            <div class="flex justify-end gap-1">
                                <button onclick="toggleCatEdit({{ $cat->id }})"
                                        class="cat-display-{{ $cat->id }} w-9 h-9 rounded-xl hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-primary transition-all active:scale-95"
                                        title="Ubah">
                                    <x-icon name="edit" class="w-[18px] h-[18px]" />
                                </button>
                                <form method="POST" action="{{ route('master-data.categories.destroy', $cat) }}"
                                      onsubmit="return confirm('Hapus kategori {{ $cat->name }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                            class="w-9 h-9 rounded-xl hover:bg-error/10 flex items-center justify-center text-on-surface-variant hover:text-error transition-all active:scale-95"
                                            title="Hapus">
                                        <x-icon name="delete" class="w-[18px] h-[18px]" />
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="3" class="px-6 py-20 text-center">
                            <x-icon name="category" class="w-5 h-5 text-outline-variant/30 mb-3 block" />
                            <p class="text-on-surface-variant font-bold">Belum ada kategori aset.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($categories->hasPages())
        <div class="px-6 sm:px-8 py-4 border-t border-surface-container-low/50">
            {{ $categories->appends(['tab' => 'categories'])->links() }}
        </div>
        @endif
    </div>
</div>

{{-- TAB: Status --}}
<div x-cloak x-show="activeTab === 'statuses'" x-transition.opacity.duration.300ms class="grid grid-cols-1 xl:grid-cols-12 gap-6 sm:gap-8">
    {{-- Add Form --}}
    <div class="xl:col-span-4 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm border border-surface-container-low h-fit">
        <h3 class="font-extrabold text-on-surface font-headline text-lg mb-6 flex items-center gap-2">
            <x-icon name="sell" class="w-5 h-5 text-primary" /> Tambah Status
        </h3>
        <form method="POST" action="{{ route('master-data.statuses.store') }}" class="space-y-6">
            @csrf
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Status</label>
                <input type="text" name="name" required
                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold"
                       placeholder="Contoh: Menunggu Pembayaran" />
            </div>
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Pilih Warna Label</label>
                <div class="flex items-center gap-4 bg-surface-container-low p-3 rounded-xl shadow-inner border border-surface-container">
                    <input type="color" name="color" value="#d97706" id="statusColorPicker"
                           class="w-12 h-12 rounded-xl border-2 border-surface-container-high cursor-pointer p-0 shadow-sm shrink-0" />
                    <input type="text" id="statusColorText" value="#D97706" readonly
                           class="bg-transparent border-none p-0 text-sm font-mono font-bold w-full focus:ring-0 uppercase" />
                </div>
            </div>
            <button type="submit"
                    class="w-full bg-primary text-on-primary py-3.5 rounded-xl text-sm font-bold shadow-xl shadow-primary/20 hover:bg-primary-dim transition-all active:scale-[0.98]">
                <span>Simpan Status</span>
            </button>
        </form>
    </div>

    {{-- Statuses Table --}}
    <div class="xl:col-span-8 bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden border border-surface-container-low flex flex-col">
        <div class="px-6 sm:px-8 py-6 bg-surface-container-low/45 border-b border-surface-container-low">
            <h3 class="font-bold text-on-surface font-headline">Manajemen Status Prospek</h3>
            <p class="text-xs text-on-surface-variant mt-1">{{ $statuses->total() }} status aktif dalam sistem</p>
        </div>
        <div class="table-scroll-mobile overflow-x-auto scrollbar-thin scrollbar-thumb-surface-container">
            <table class="w-full min-w-[550px] text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-surface-container-low/50">
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Urutan</th>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Nama Label</th>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-right leading-none">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-low">
                    @forelse($statuses as $status)
                    <tr class="hover:bg-surface-container-low/30 transition-colors group">
                        <td class="px-6 sm:px-8 py-4 text-xs font-bold text-on-surface-variant/40">{{ $status->sort_order }}</td>
                        <td class="px-6 sm:px-8 py-4">
                            <div class="status-display-{{ $status->id }} flex items-center gap-3">
                                <span class="w-3 h-3 rounded-full shrink-0 shadow-sm" style="background-color: {{ $status->color }}"></span>
                                <span class="font-bold text-sm text-on-surface">{{ $status->name }}</span>
                                <span class="text-[9px] font-mono font-bold text-on-surface-variant px-1.5 py-0.5 bg-surface-container rounded opacity-0 group-hover:opacity-100 transition-opacity">{{ strtoupper($status->color) }}</span>
                            </div>
                            <form method="POST" action="{{ route('master-data.statuses.update', $status) }}"
                                  class="status-edit-{{ $status->id }} hidden flex items-center gap-2 max-w-sm">
                                @csrf @method('PUT')
                                <input type="text" name="name" value="{{ $status->name }}"
                                       class="flex-1 bg-surface-container-low border border-primary/30 rounded-lg px-3 py-1.5 text-sm focus:ring-2 focus:ring-primary/20 font-bold shadow-sm"/>
                                <input type="color" name="color" value="{{ $status->color }}"
                                       class="w-9 h-9 rounded-lg border-2 border-surface-container-high cursor-pointer p-0 shadow-md shrink-0"/>
                                <button type="submit" class="text-primary hover:text-primary-dim p-1" title="Simpan"><x-icon name="done" class="w-5 h-5" /></button>
                                <button type="button" onclick="toggleStatusEdit({{ $status->id }})" class="text-error p-1" title="Batal"><x-icon name="close" class="w-5 h-5" /></button>
                            </form>
                        </td>
                        <td class="px-6 sm:px-8 py-4 text-right">
                            <div class="flex justify-end gap-1">
                                <button onclick="toggleStatusEdit({{ $status->id }})"
                                        class="status-display-{{ $status->id }} w-9 h-9 rounded-xl hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-primary transition-all active:scale-95">
                                    <x-icon name="edit" class="w-[18px] h-[18px]" />
                                </button>
                                <form method="POST" action="{{ route('master-data.statuses.destroy', $status) }}"
                                      onsubmit="return confirm('Hapus status {{ $status->name }}?')">
                                    @csrf @method('DELETE')
                                    <button type="submit"
                                            class="w-9 h-9 rounded-xl hover:bg-error/10 flex items-center justify-center text-on-surface-variant hover:text-error transition-all active:scale-95">
                                        <x-icon name="delete" class="w-[18px] h-[18px]" />
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 py-20 text-center">
                            <x-icon name="label" class="w-12 h-12 text-outline-variant/30 mb-3 block" />
                            <p class="text-on-surface-variant font-bold">Belum ada label status.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

{{-- TAB: Users --}}
<div x-cloak x-show="activeTab === 'users'" x-transition.opacity.duration.300ms class="grid grid-cols-1 xl:grid-cols-12 gap-6 sm:gap-8">
    {{-- Add Form --}}
    <div class="xl:col-span-4 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm border border-surface-container-low h-fit">
        <h3 class="font-extrabold text-on-surface font-headline text-lg mb-6 flex items-center gap-2">
            <x-icon name="person_add" class="w-5 h-5 text-primary" /> Tambah User Baru
        </h3>
        <form method="POST" action="{{ route('master-data.users.store') }}" class="space-y-5">
            @csrf
            <input type="hidden" name="tab" value="users">
            <input type="hidden" name="search_user" value="{{ request('search_user') }}">
            <input type="hidden" name="users_page" value="{{ request('users_page', $users->currentPage()) }}">
            @if($createUserErrors->any())
            <div class="rounded-2xl border border-error/20 bg-error/10 px-4 py-3 text-sm text-error" role="alert">
                <p class="font-bold">User belum berhasil ditambahkan.</p>
                <p class="mt-1 text-xs font-medium text-on-surface-variant">{{ $createUserErrors->first() }}</p>
            </div>
            @endif
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Lengkap</label>
                <input type="text" name="name" required value="{{ $isCreateUserContext ? old('name') : '' }}"
                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold"
                       placeholder="Nama Administrator" />
                @if($createUserErrors->has('name'))
                <p class="px-1 text-xs font-medium text-error">{{ $createUserErrors->first('name') }}</p>
                @endif
            </div>
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Alamat Email</label>
                <input type="email" name="email" required value="{{ $isCreateUserContext ? old('email') : '' }}"
                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold"
                       placeholder="admin@akun.com" />
                @if($createUserErrors->has('email'))
                <p class="px-1 text-xs font-medium text-error">{{ $createUserErrors->first('email') }}</p>
                @endif
            </div>
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Set Password</label>
                <div class="relative">
                    <input :type="showCreateUserPasswords ? 'text' : 'password'" name="password" required
                           x-model="createUserPassword"
                           :aria-invalid="hasCreateUserPasswordMismatch() ? 'true' : 'false'"
                           class="w-full bg-surface-container-low border-0 rounded-xl pl-4 pr-12 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold transition"
                           :class="hasCreateUserPasswordMismatch() ? 'ring-2 ring-error/40' : ''"
                           placeholder="Minimal 8 karakter" />
                    <button type="button"
                            @click="toggleCreateUserPasswordsVisibility()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-on-surface-variant transition hover:bg-surface-container hover:text-on-surface"
                            :aria-label="showCreateUserPasswords ? 'Sembunyikan password' : 'Tampilkan password'"
                            :title="showCreateUserPasswords ? 'Sembunyikan password' : 'Tampilkan password'">
                        <x-icon x-show="!showCreateUserPasswords" x-cloak name="visibility" class="w-5 h-5" />
                        <x-icon x-show="showCreateUserPasswords" x-cloak name="visibility_off" class="w-5 h-5" />
                    </button>
                </div>
                @if($createUserErrors->has('password'))
                <p class="px-1 text-xs font-medium text-error">{{ $createUserErrors->first('password') }}</p>
                @endif
            </div>
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Konfirmasi Password</label>
                <div class="relative">
                    <input :type="showCreateUserPasswords ? 'text' : 'password'" name="password_confirmation" required
                           x-model="createUserPasswordConfirmation"
                           @input="createUserPasswordConfirmationTouched = true"
                           @blur="createUserPasswordConfirmationTouched = true"
                           :aria-invalid="hasCreateUserPasswordMismatch() ? 'true' : 'false'"
                           class="w-full bg-surface-container-low border-0 rounded-xl pl-4 pr-12 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold transition"
                           :class="hasCreateUserPasswordMismatch() ? 'ring-2 ring-error/40' : ''"
                           placeholder="Ulangi password yang sama" />
                    <button type="button"
                            @click="toggleCreateUserPasswordsVisibility()"
                            class="absolute right-3 top-1/2 -translate-y-1/2 rounded-lg p-1.5 text-on-surface-variant transition hover:bg-surface-container hover:text-on-surface"
                            :aria-label="showCreateUserPasswords ? 'Sembunyikan password' : 'Tampilkan password'"
                            :title="showCreateUserPasswords ? 'Sembunyikan password' : 'Tampilkan password'">
                        <x-icon x-show="!showCreateUserPasswords" x-cloak name="visibility" class="w-5 h-5" />
                        <x-icon x-show="showCreateUserPasswords" x-cloak name="visibility_off" class="w-5 h-5" />
                    </button>
                </div>
                <p x-cloak
                   x-show="hasCreateUserPasswordMismatch()"
                   x-text="getCreateUserPasswordConfirmationError()"
                   class="px-1 text-xs font-medium text-error"></p>
                @if($createUserErrors->has('password_confirmation'))
                <p class="px-1 text-xs font-medium text-error">{{ $createUserErrors->first('password_confirmation') }}</p>
                @endif
            </div>
            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                <div class="space-y-2">
                    <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Pilih Role</label>
                    <input type="hidden" name="role" id="roleSelect" :value="createUserRole" required>
                    <div class="relative isolate grid grid-cols-2 rounded-2xl bg-surface-container-low/80 p-1 shadow-inner ring-1 ring-surface-container/80 backdrop-blur-sm">
                        <span aria-hidden="true"
                              class="pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-[1rem] bg-primary shadow-[0_10px_24px_rgb(var(--color-primary-rgb)/0.24)] transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
                              :class="createUserRole === 'super_admin' ? 'translate-x-full' : 'translate-x-0'"></span>
                        <button type="button"
                                @click="createUserRole = 'admin'"
                                class="relative z-10 rounded-[1rem] px-2.5 py-2 text-xs font-semibold leading-tight tracking-tight transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                :class="createUserRole === 'admin'
                                    ? 'text-on-primary'
                                    : 'text-on-surface-variant hover:text-on-surface'">
                            Admin
                        </button>
                        <button type="button"
                                @click="createUserRole = 'super_admin'"
                                class="relative z-10 rounded-[1rem] px-2.5 py-2 text-xs font-semibold leading-tight tracking-tight transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                :class="createUserRole === 'super_admin'
                                    ? 'text-on-primary'
                                    : 'text-on-surface-variant hover:text-on-surface'">
                            Super Admin
                        </button>
                    </div>
                </div>
                <div class="space-y-2"
                     id="accountField"
                     x-show="createUserRole !== 'super_admin'"
                     x-transition.opacity.duration.150ms>
                    <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Pilih Akun</label>
                    <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => '-- Akun --']])->concat($accounts->map(fn($account) => ['value' => (string) $account->id, 'label' => $account->name])->values())), @js($isCreateUserContext ? (string) old('account_id', '') : ''))"
                         @click.outside="close()"
                         @keydown.escape.prevent.stop="close()"
                         class="relative z-10">
                        <input type="hidden" name="account_id" :value="createUserRole === 'super_admin' ? '' : selected">
                        <button type="button"
                                @click="toggle()"
                                class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                :class="open ? 'ring-2 ring-primary/20' : ''"
                                :aria-expanded="open.toString()"
                                aria-haspopup="listbox">
                            <span class="block truncate font-bold text-on-surface"
                                  x-text="selectedLabel('-- Akun --')"></span>
                        </button>
                        <x-icon name="expand_more"
                                class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                                x-bind:class="open ? 'rotate-180' : ''" />
                        <div x-show="open"
                             x-cloak
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute left-0 right-0 top-full z-20 mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
                            <div class="border-b border-surface-container-low p-3">
                                <input x-ref="searchInput" type="text" x-model="search"
                                       class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm shadow-inner focus:ring-2 focus:ring-primary/20"
                                       placeholder="Cari akun..." autocomplete="off">
                            </div>
                            <div class="max-h-60 overflow-y-auto p-1.5">
                                <template x-if="filteredOptions().length === 0">
                                    <div class="px-4 py-3 text-sm text-outline-variant">Akun tidak ditemukan.</div>
                                </template>
                                <template x-for="option in filteredOptions()" :key="option.value">
                                    <button type="button" @mousedown.prevent="setSelected(option.value)"
                                            class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary"
                                            :class="selected === option.value ? 'bg-primary/10 text-primary' : 'text-on-surface'">
                                        <span class="truncate font-semibold" x-text="option.label"></span>
                                        <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                    @if($createUserErrors->has('account_id'))
                    <p class="px-1 text-xs font-medium text-error">{{ $createUserErrors->first('account_id') }}</p>
                    @endif
                </div>
            </div>
            <button type="submit"
                    class="w-full bg-primary text-on-primary py-3.5 rounded-xl font-bold shadow-xl shadow-primary/20 hover:scale-[1.02] active:scale-[0.98] transition-all flex items-center justify-center gap-2 mt-4 text-sm">
                <span>Daftarkan Administrator</span>
            </button>
        </form>
    </div>

    {{-- Users Table --}}
    <div class="xl:col-span-8 bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden border border-surface-container-low flex flex-col">
        <div class="px-6 sm:px-8 py-6 bg-surface-container-low/45 border-b border-surface-container-low flex flex-col sm:flex-row items-start sm:items-center justify-between gap-4">
            <div>
                <h3 class="font-bold text-on-surface font-headline">Daftar Akun Pengguna</h3>
                <p class="text-xs text-on-surface-variant mt-1">{{ $users->total() }} user pengguna sistem aktif</p>
            </div>
            
            <form method="GET" action="{{ route('master-data.index') }}" class="w-full sm:w-auto relative flex gap-2">
                <input type="hidden" name="tab" value="users">
                <div class="relative flex-1 sm:w-64">
                    <span class="absolute inset-y-0 left-3 flex items-center text-outline-variant">
                        <x-icon name="search" class="w-4 h-4" />
                    </span>
                    <input type="text" name="search_user" value="{{ request('search_user') }}" 
                           placeholder="Cari user, email, atau akun..." 
                           class="w-full bg-surface-container-low border-0 rounded-xl pl-9 pr-3 py-2 text-sm focus:ring-2 focus:ring-primary/20">
                </div>
                <button type="submit" class="bg-primary/10 text-primary px-4 py-2 rounded-xl text-sm font-bold hover:bg-primary/20 transition-all">
                    Cari
                </button>
                @if(request('search_user'))
                    <a href="{{ route('master-data.index', ['tab' => 'users']) }}" class="flex items-center justify-center p-2 text-on-surface-variant hover:text-error transition-colors" title="Reset Search">
                        <x-icon name="close" class="w-4 h-4" />
                    </a>
                @endif
            </form>
        </div>
        <div class="table-scroll-mobile overflow-x-auto scrollbar-thin scrollbar-thumb-surface-container">
            <table class="w-full min-w-[620px] sm:min-w-[700px] text-left border-collapse">
                <thead>
                    <tr class="bg-surface-container-low/50">
                        <th class="px-4 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Administrator</th>
                        <th class="w-[116px] min-w-[116px] px-3 sm:px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-tight">Akses Level</th>
                        <th class="w-[140px] min-w-[140px] px-3 sm:px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-tight">Akun</th>
                        <th class="w-[120px] min-w-[120px] px-4 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-right leading-none">Aksi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-low">
                    @forelse($users as $u)
                    <tr class="hover:bg-surface-container-low/30 transition-colors group">
                        <td class="px-4 sm:px-8 py-4">
                            <div class="flex items-center gap-3">
                                <div class="w-9 h-9 rounded-full flex items-center justify-center font-bold text-xs shrink-0 shadow-sm ring-2 ring-surface-container-high
                                            {{ $u->isSuperAdmin() ? 'bg-primary-container text-primary' : 'bg-secondary-container text-secondary' }}">
                                    {{ strtoupper(substr($u->name, 0, 2)) }}
                                </div>
                                <div class="min-w-0">
                                    <span class="font-bold text-sm text-on-surface block truncate max-w-[130px] sm:max-w-[150px]">{{ $u->name }}</span>
                                    <span class="text-[10px] text-on-surface-variant font-medium block truncate max-w-[150px] sm:max-w-[180px]">{{ $u->email }}</span>
                                </div>
                            </div>
                        </td>
                        <td class="px-3 sm:px-6 py-4 align-middle">
                            @if($u->isSuperAdmin())
                            <span class="inline-flex max-w-full items-center justify-center whitespace-nowrap rounded-full bg-primary px-2.5 sm:px-3 py-1 text-[9px] font-extrabold uppercase tracking-[0.18em] text-on-primary shadow-sm">Super Admin</span>
                            @else
                            <span class="inline-flex max-w-full items-center justify-center whitespace-nowrap rounded-full border border-secondary/10 bg-secondary-container px-2.5 sm:px-3 py-1 text-[9px] font-extrabold uppercase tracking-[0.18em] text-secondary-dim">Admin Akun</span>
                            @endif
                        </td>
                        <td class="px-3 sm:px-6 py-4">
                            <span class="block max-w-[140px] whitespace-normal break-words text-sm font-bold leading-snug text-on-surface-variant sm:max-w-[280px]">{{ $u->account ? $u->account->name : 'Akses Pusat' }}</span>
                        </td>
                        <td class="px-4 sm:px-8 py-4 text-right">
                            <div class="flex justify-end gap-1 items-center whitespace-nowrap">
                                <button type="button"
                                        @click="openEditUser({
                                            id: '{{ $u->id }}',
                                            name: @js($u->name),
                                            email: @js($u->email),
                                            role: '{{ $u->role->value }}',
                                            account_id: '{{ (string) ($u->account_id ?? '') }}'
                                        })"
                                        class="w-9 h-9 rounded-xl hover:bg-surface-container flex items-center justify-center text-on-surface-variant/40 hover:text-primary transition-all active:scale-90"
                                        title="Edit User">
                                    <x-icon name="edit" class="w-5 h-5" />
                                </button>
                                @if($u->id !== auth()->id())
                                <button type="button" onclick="promptResetPassword({{ $u->id }}, '{{ addslashes($u->name) }}')"
                                        class="w-9 h-9 rounded-xl hover:bg-primary/10 flex items-center justify-center text-on-surface-variant/40 hover:text-primary transition-all active:scale-90"
                                        title="Reset Password">
                                    <x-icon name="lock_reset" class="w-5 h-5" />
                                </button>
                                <form method="POST" action="{{ route('master-data.users.destroy', $u) }}"
                                      onsubmit="return confirm('Hapus user {{ $u->name }}?')">
                                    @csrf @method('DELETE')
                                    <input type="hidden" name="tab" value="users">
                                    <input type="hidden" name="search_user" value="{{ request('search_user') }}">
                                    <input type="hidden" name="users_page" value="{{ request('users_page', $users->currentPage()) }}">
                                    <button type="submit"
                                            class="w-9 h-9 rounded-xl hover:bg-error/10 flex items-center justify-center text-on-surface-variant/40 hover:text-error transition-all active:scale-90"
                                            title="Hapus">
                                        <x-icon name="delete" class="w-5 h-5" />
                                    </button>
                                </form>
                                @else
                                <span class="text-[10px] font-bold text-on-surface-variant italic opacity-50 px-2 tracking-widest uppercase">My Profile</span>
                                @endif
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 py-20 text-center">
                            <x-icon name="person_search" class="w-12 h-12 text-outline-variant/30 mb-2 block" />
                            <p class="text-on-surface-variant font-bold">Belum ada pengguna terdaftar.</p>
                        </td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if($users->hasPages())
        <div class="px-6 sm:px-8 py-4 border-t border-surface-container-low/50">
            {{ $users->appends(['tab' => 'users'])->links() }}
        </div>
        @endif
</div>
</div>

<template x-teleport="body">
    <div x-show="showEditUserModal"
         x-cloak
         class="consultation-modal-backdrop fixed inset-0 z-[70] flex items-end justify-center p-0 sm:items-center sm:p-4"
         x-transition:enter="transition ease-out duration-200"
         x-transition:enter-start="opacity-0"
         x-transition:enter-end="opacity-100"
         x-transition:leave="transition ease-in duration-150"
         x-transition:leave-start="opacity-100"
         x-transition:leave-end="opacity-0">
        <div @click.away="closeEditUserModal()"
             class="modal-surface-glow flex max-h-[92vh] w-full max-w-3xl flex-col overflow-hidden rounded-t-3xl bg-surface-container-lowest shadow-2xl shadow-primary/10 ring-1 ring-white/5 sm:max-w-xl sm:rounded-3xl">
            <div class="flex items-start justify-between gap-4 border-b border-surface-container-low px-5 py-4 sm:px-6 sm:py-5">
                <div>
                    <h3 class="font-headline text-lg font-extrabold text-on-surface sm:text-xl">Edit User</h3>
                    <p class="mt-1 text-xs font-medium text-on-surface-variant">Perbarui data administrator tanpa mengubah password.</p>
                </div>
                <button type="button"
                        @click="closeEditUserModal()"
                        class="flex h-9 w-9 items-center justify-center rounded-full bg-surface-container text-on-surface-variant transition hover:bg-error/10 hover:text-error">
                    <x-icon name="close" class="h-5 w-5" />
                </button>
            </div>

            <form method="POST"
                  :action="buildUserUpdateUrl(editUser.id)"
                  class="flex-1 overflow-y-auto px-5 py-5 sm:px-6 sm:py-6">
                @csrf
                @method('PUT')
                <input type="hidden" name="tab" value="users">
                <input type="hidden" name="search_user" value="{{ request('search_user') }}">
                <input type="hidden" name="users_page" value="{{ request('users_page', $users->currentPage()) }}">
                <input type="hidden" name="edit_user_id" :value="editUser.id">

                @if($editUserErrors->any())
                <div class="mb-5 rounded-2xl border border-error/20 bg-error/10 px-4 py-3 text-sm text-error" role="alert">
                    <p class="font-bold">Perubahan user belum tersimpan.</p>
                    <p class="mt-1 text-xs font-medium text-on-surface-variant">{{ $editUserErrors->first() }}</p>
                </div>
                @endif

                <div class="grid grid-cols-1 gap-5 sm:grid-cols-2 sm:gap-x-5 sm:gap-y-5">
                <div class="space-y-2">
                    <label class="block px-1 text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">Nama Lengkap</label>
                    <input type="text"
                           name="name"
                           x-model="editUser.name"
                           required
                           class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm font-bold shadow-inner focus:ring-2 focus:ring-primary/20"
                           placeholder="Nama Administrator">
                    @if($editUserErrors->has('name'))
                    <p class="px-1 text-xs font-medium text-error">{{ $editUserErrors->first('name') }}</p>
                    @endif
                </div>

                <div class="space-y-2"
                     x-show="editUser.role !== 'super_admin'"
                     x-transition.opacity.duration.150ms>
                    <label class="block px-1 text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">Pilih Akun</label>
                    <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => '-- Akun --']])->concat($accounts->map(fn($account) => ['value' => (string) $account->id, 'label' => $account->name])->values())))"
                         x-init="$watch('selected', value => editUser.account_id = value); selected = editUser.account_id || ''"
                         x-effect="if (selected !== String(editUser.account_id || '')) selected = String(editUser.account_id || '')"
                         @click.outside="close()"
                         @keydown.escape.prevent.stop="close()"
                         class="relative">
                        <input type="hidden" name="account_id" :value="editUser.role === 'super_admin' ? '' : selected">
                        <button type="button"
                                @click="toggle()"
                                class="w-full rounded-xl bg-surface-container-low py-3 pl-4 pr-12 text-left text-sm font-bold shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                :class="open ? 'ring-2 ring-primary/20' : ''"
                                :aria-expanded="open.toString()"
                                aria-haspopup="listbox">
                            <span class="block truncate text-on-surface" x-text="selectedLabel('-- Akun --')"></span>
                        </button>
                        <x-icon name="expand_more"
                                class="pointer-events-none absolute right-4 top-1/2 h-5 w-5 -translate-y-1/2 text-outline-variant transition-transform"
                                x-bind:class="open ? 'rotate-180' : ''" />
                        <div x-show="open"
                             x-cloak
                             x-transition:enter="transition ease-out duration-150"
                             x-transition:enter-start="opacity-0 -translate-y-1"
                             x-transition:enter-end="opacity-100 translate-y-0"
                             class="absolute left-0 right-0 top-full z-[80] mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
                            <div class="border-b border-surface-container-low p-3">
                                <input x-ref="searchInput" type="text" x-model="search"
                                       class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm shadow-inner focus:ring-2 focus:ring-primary/20"
                                       placeholder="Cari akun..." autocomplete="off">
                            </div>
                            <div class="max-h-48 overflow-y-auto p-1.5 sm:max-h-60">
                                <template x-if="filteredOptions().length === 0">
                                    <div class="px-4 py-3 text-sm text-outline-variant">Akun tidak ditemukan.</div>
                                </template>
                                <template x-for="option in filteredOptions()" :key="option.value">
                                    <button type="button"
                                            @mousedown.prevent="setSelected(option.value)"
                                            class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                        <span class="truncate font-semibold" x-text="option.label"></span>
                                        <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                    </button>
                                </template>
                            </div>
                        </div>
                    </div>
                    @if($editUserErrors->has('account_id'))
                    <p class="px-1 text-xs font-medium text-error">{{ $editUserErrors->first('account_id') }}</p>
                    @endif
                </div>

                <div class="space-y-2">
                    <label class="block px-1 text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">Alamat Email</label>
                    <input type="email"
                           name="email"
                           x-model="editUser.email"
                           required
                           class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm font-bold shadow-inner focus:ring-2 focus:ring-primary/20"
                           placeholder="admin@akun.com">
                    @if($editUserErrors->has('email'))
                    <p class="px-1 text-xs font-medium text-error">{{ $editUserErrors->first('email') }}</p>
                    @endif
                </div>

                    <div class="space-y-2">
                        <label class="block px-1 text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">Pilih Role</label>
                        <input type="hidden" name="role" :value="editUser.role || 'admin'" required>
                        <div class="relative isolate grid grid-cols-2 rounded-2xl bg-surface-container-low/80 p-1 shadow-inner ring-1 ring-surface-container/80 backdrop-blur-sm">
                            <span aria-hidden="true"
                                  class="pointer-events-none absolute inset-y-1 left-1 w-[calc(50%-0.25rem)] rounded-[1rem] bg-primary shadow-[0_10px_24px_rgb(var(--color-primary-rgb)/0.24)] transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                  :class="(editUser.role || 'admin') === 'super_admin' ? 'translate-x-full' : 'translate-x-0'"></span>
                            <button type="button"
                                    @click="editUser.role = 'admin'"
                                    class="relative z-10 rounded-[1rem] px-2.5 py-2 text-xs font-semibold leading-tight tracking-tight transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                    :class="(editUser.role || 'admin') === 'admin'
                                        ? 'text-on-primary'
                                        : 'text-on-surface-variant hover:text-on-surface'">
                                Admin
                            </button>
                            <button type="button"
                                    @click="editUser.role = 'super_admin'"
                                    class="relative z-10 rounded-[1rem] px-2.5 py-2 text-xs font-semibold leading-tight tracking-tight transition-all duration-500 ease-[cubic-bezier(0.22,1,0.36,1)]"
                                    :class="(editUser.role || 'admin') === 'super_admin'
                                        ? 'text-on-primary'
                                        : 'text-on-surface-variant hover:text-on-surface'">
                                Super Admin
                            </button>
                        </div>
                    </div>
                </div>

                <div class="sticky bottom-0 mt-5 flex flex-col-reverse gap-3 border-t border-surface-container-low bg-surface-container-lowest pt-5 sm:flex-row sm:justify-end">
                    <button type="button"
                            @click="closeEditUserModal()"
                            class="w-full rounded-xl px-5 py-3 text-sm font-bold text-on-surface-variant transition hover:bg-surface-container-low sm:w-auto">
                        Batal
                    </button>
                    <button type="submit"
                            class="w-full rounded-xl bg-primary px-6 py-3 text-sm font-bold text-on-primary shadow-lg shadow-primary/20 transition hover:bg-primary-dim sm:w-auto">
                        Simpan Perubahan
                    </button>
                </div>
            </form>
        </div>
    </div>
</template>
</div> {{-- End of Alpine wrapper --}}

@endsection
