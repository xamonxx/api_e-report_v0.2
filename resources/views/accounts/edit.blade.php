@extends('layouts.app')
@section('title', 'Edit Akun')

@section('content')
<div class="max-w-2xl mx-auto px-1 sm:px-0">
    {{-- Header --}}
    <div class="flex items-center gap-4 mb-8">
        <a href="{{ route('accounts.index') }}" class="w-10 h-10 rounded-xl bg-surface-container flex items-center justify-center text-on-surface-variant hover:bg-surface-container-high transition-all active:scale-90 shrink-0 shadow-sm">
            <x-icon name="arrow_back" class="w-5 h-5" />
        </a>
        <div class="min-w-0">
            <h2 class="text-2xl font-extrabold text-on-surface font-headline truncate pr-2">Update Profil Akun</h2>
            <p class="text-[10px] sm:text-xs text-on-surface-variant mt-1.5 font-bold uppercase tracking-widest opacity-70 truncate px-0.5">Edit: {{ $account->name }}</p>
        </div>
    </div>

    {{-- Form Card --}}
    <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-6 sm:p-8 border border-surface-container-low">
        @if($errors->any())
        <div class="bg-error/10 text-error px-4 py-3 rounded-xl text-sm font-medium mb-6">
            <div class="flex items-center gap-2 mb-2">
                <x-icon name="error" class="w-[18px] h-[18px]" />
                <span class="font-bold">Gagal memperbarui data:</span>
            </div>
            <ul class="list-disc list-inside text-xs space-y-1 opacity-90">
                @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        <form method="POST" action="{{ route('accounts.update', $account) }}" enctype="multipart/form-data" class="space-y-6 sm:space-y-8" id="accountForm">
            @csrf @method('PUT')
            <input type="hidden" name="remove_logo" value="0" id="removeLogoField" />
            
            {{-- Logo Section --}}
            <div class="space-y-3">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Logo / Branding Akun</label>
                <div class="flex flex-col sm:flex-row items-center gap-6 p-4 bg-surface-container-low rounded-2xl border border-surface-container shadow-inner">
                    <div id="logoPreviewContainer">
                        @if($account->logo_path)
                            <img src="{{ Storage::url($account->logo_path) }}" alt="{{ $account->name }} Logo" loading="lazy" id="logoPreview" class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl object-cover shadow-sm bg-white p-1 ring-1 ring-surface-container" />
                        @else
                            <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-white flex items-center justify-center text-primary shadow-sm border border-surface-container shrink-0" id="logoPlaceholder">
                                <x-icon name="domain" class="w-8 h-8" />
                            </div>
                        @endif
                    </div>
                    <div class="flex-1 w-full">

                        <input type="file" name="logo" accept="image/*" id="logoInput" class="block w-full text-xs text-on-surface-variant
                            file:mr-4 file:py-2 file:px-4
                            file:rounded-xl file:border-0
                            file:text-xs file:font-bold
                            file:bg-primary file:text-on-primary
                            hover:file:bg-primary-dim transition-all cursor-pointer shadow-sm
                        "/>
                        @if($account->logo_path)
                        <div class="flex items-center gap-2 mt-2" id="logoActions">
                            <button type="button" id="removeLogoBtn" class="text-[9px] font-bold text-error bg-error/10 px-2 py-1 rounded-lg hover:bg-error/20 transition-all">
                                Hapus Logo
                            </button>
                        </div>
                        @endif
                        <p class="text-[9px] text-on-surface-variant mt-2 font-medium italic opacity-60">Gunakan format JPG/PNG, ukuran ideal 200x200px.</p>
                    </div>
                </div>
            </div>

            {{-- Account Name --}}
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Akun <span class="text-error">*</span></label>
                <input type="text" name="name" value="{{ old('name', $account->name) }}" 
                       minlength="3" maxlength="100"
                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3.5 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-outline-variant shadow-inner font-bold" 
                       required />
            </div>

            {{-- Category/Description --}}
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kategori / Tagline Akun</label>
                <input type="text" name="description" value="{{ old('description', $account->description) }}" 
                       maxlength="120"
                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold" />
            </div>


            {{-- Target Setting --}}
            <div class="space-y-2">
                <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Target Leads Bulanan</label>
                <div class="relative w-full sm:max-w-[180px]">
                    <input type="number" name="target_leads" value="{{ old('target_leads', $account->target_leads) }}" 
                           class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold text-center" min="1" max="1000000" />
                </div>
            </div>

            {{-- Actions --}}
            <div class="form-actions-responsive pt-6 border-t border-surface-container-low">
                <a href="{{ route('accounts.index') }}" 
                   class="flex-1 sm:flex-none flex items-center justify-center border border-outline-variant/30 text-on-surface-variant px-8 py-3.5 rounded-xl text-sm font-bold hover:bg-surface-container transition-all active:scale-95">
                    Batal
                </a>
                <button type="submit" 
                        class="flex-1 sm:flex-none flex items-center justify-center bg-primary text-on-primary px-10 py-3.5 rounded-xl font-bold text-sm shadow-xl shadow-primary/20 hover:bg-primary-dim transition-all hover:scale-[1.02] active:scale-[0.98] gap-2">
                    <x-icon name="save_as" class="w-4 h-4" />
                    <span>Simpan Perubahan</span>
                </button>
            </div>
        </form>
    </div>
</div>

@push('scripts')
<script>
document.addEventListener('DOMContentLoaded', function() {
    const logoInput = document.getElementById('logoInput');
    const logoPreviewContainer = document.getElementById('logoPreviewContainer');
    const removeLogoBtn = document.getElementById('removeLogoBtn');
    const removeLogoField = document.getElementById('removeLogoField');
    
    let originalLogoUrl = '{{ $account->logo_path ? Storage::url($account->logo_path) : '' }}';
    let newLogoFile = null;
    let logoRemoved = false;

    logoInput.addEventListener('change', function(e) {
        const file = e.target.files[0];
        
        if (file) {
            newLogoFile = file;
            logoRemoved = false;
            removeLogoField.value = '0';
            
            const reader = new FileReader();
            
            reader.onload = function(e) {
                logoPreviewContainer.innerHTML = `
                    <img src="${e.target.result}" alt="Preview Logo" class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl object-cover shadow-sm bg-white p-1 ring-1 ring-primary/50" />
                `;
            };
            
            reader.readAsDataURL(file);
        }
    });

    if (removeLogoBtn) {
        removeLogoBtn.addEventListener('click', function(e) {
            e.preventDefault();
            
            newLogoFile = null;
            logoInput.value = '';
            logoRemoved = true;
            removeLogoField.value = '1';
            
            logoPreviewContainer.innerHTML = `
                <div class="w-16 h-16 sm:w-20 sm:h-20 rounded-2xl bg-white flex items-center justify-center text-primary shadow-sm border border-surface-container shrink-0" id="logoPlaceholder">
                    <svg class="w-8 h-8" fill="currentColor" viewBox="0 0 24 24"><path d="M12 7V3H2v18h20V7H12zM6 19H4v-2h2v2zm0-4H4v-2h2v2zm0-4H4V9h2v2zm0-4H4V5h2v2zm4 12H8v-2h2v2zm0-4H8v-2h2v2zm0-4H8V9h2v2zm0-4H8V5h2v2zm10 12h-8v-2h8v2zm0-4h-8v-2h8v2zm0-4h-8V9h8v2zm0-4h-8V5h8v2z"/></svg>
                </div>
            `;
        });
    }
});
</script>
@endpush
@endsection
