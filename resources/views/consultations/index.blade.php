@extends('layouts.app')
@section('title', 'Leads')

@section('content')
{{-- Page Header --}}
<div x-data="consultationsPage({
        showImportModal: {{ $errors->has('csv_file') ? 'true' : 'false' }},
        showCreateModal: {{ old('client_name') ? 'true' : 'false' }},
        createUrl: @js(route('consultations.create'))
    })"
    x-init="init()"
    @open-create-modal.window="openCreateLead()"
    id="consultations-page"
    class="flex flex-col xl:flex-row xl:items-end justify-between gap-6 mb-6">
    <div>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-on-surface tracking-tight font-headline">Leads Management</h2>
        <p class="text-sm sm:text-base text-on-surface-variant mt-1">Kelola semua data konsultasi klien.</p>
    </div>
    <div class="flex flex-wrap gap-2 sm:gap-3 no-print">
        <button @click="showImportModal = true"
           class="flex-1 sm:flex-none border border-outline-variant/30 text-on-surface-variant px-3 sm:px-4 py-2.5 rounded-xl text-xs sm:text-sm font-semibold hover:bg-surface-container transition-colors flex items-center justify-center gap-2">
            <x-icon name="upload_file" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            <span>Import</span>
        </button>
        <a href="{{ route('export.leads.excel', request()->query()) }}"
           class="flex-1 sm:flex-none border border-outline-variant/30 text-on-surface-variant px-3 sm:px-4 py-2.5 rounded-xl text-xs sm:text-sm font-semibold hover:bg-surface-container transition-colors flex items-center justify-center gap-2">
            <x-icon name="download" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            <span>Excel</span>
        </a>
        <a href="{{ route('export.leads.pdf', request()->query()) }}"
           class="flex-1 sm:flex-none border border-outline-variant/30 text-on-surface-variant px-3 sm:px-4 py-2.5 rounded-xl text-xs sm:text-sm font-semibold hover:bg-surface-container transition-colors flex items-center justify-center gap-2">
            <x-icon name="print" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            <span>PDF</span>
        </a>
        <button @click="openCreateLead()"
           class="w-full sm:w-auto bg-primary text-on-primary px-6 py-2.5 rounded-xl font-bold text-xs sm:text-sm flex items-center justify-center gap-2 shadow-lg shadow-primary/20 hover:bg-primary-dim transition-colors">
            <x-icon name="add_circle" class="w-3.5 h-3.5 sm:w-4 sm:h-4" />
            <span>Tambah Lead</span>
        </button>
    </div>

    {{-- Import Modal --}}
    <template x-teleport="body">
        <div x-show="showImportModal" x-cloak class="consultation-modal-backdrop fixed inset-0 z-[60] flex items-center justify-center p-4"
             x-transition.opacity.duration.300ms>
            <div class="modal-surface-glow bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-xl w-full max-w-lg animate-fade-in">
                <div class="flex justify-between items-center mb-6">
                    <h3 class="font-bold text-on-surface font-headline text-xl">Import Data CSV</h3>
                    <button @click="showImportModal = false" class="text-on-surface-variant hover:text-error transition-colors">
                        <x-icon name="close" class="w-5 h-5" />
                    </button>
                </div>
                
                <p class="text-sm text-on-surface-variant mb-6">Unggah file CSV dengan urutan kolom: <br><code class="bg-surface-container px-1 rounded font-bold">Nama Klien, No Telepon, ID Akun</code></p>
                
                <div class="mb-6">
                    <a href="{{ route('consultations.template') }}" class="text-primary text-sm font-bold hover:underline flex items-center gap-1">
                        <x-icon name="download" class="w-3.5 h-3.5" /> Download Template Excel
                    </a>
                </div>

                <form action="{{ route('consultations.import') }}" method="POST" enctype="multipart/form-data" class="space-y-6"
                      x-data="{ selectedImportFileName: '' }">
                    @csrf
                    <div class="border-2 border-dashed border-outline-variant/30 rounded-xl p-6 sm:p-8 text-center hover:bg-surface-container-low transition-colors relative group"
                         :class="selectedImportFileName ? 'border-primary/50 bg-primary/5' : ''">
                        <input type="file" name="csv_file" accept=".csv" required
                               @change="selectedImportFileName = $event.target.files[0]?.name || ''"
                               class="absolute inset-0 w-full h-full opacity-0 cursor-pointer z-10"/>
                        <div class="space-y-2">
                            <x-icon name="cloud_upload" class="w-10 h-10 text-outline-variant group-hover:text-primary transition-colors" />
                            <p class="text-xs sm:text-sm text-on-surface-variant group-hover:text-on-surface transition-colors font-medium"
                               x-show="!selectedImportFileName">Klik atau drop file CSV di sini</p>
                            <div x-show="selectedImportFileName" x-cloak class="space-y-1">
                                <p class="text-[10px] font-bold uppercase tracking-widest text-primary">File dipilih</p>
                                <p class="text-xs sm:text-sm font-bold text-on-surface break-all" x-text="selectedImportFileName"></p>
                            </div>
                        </div>
                    </div>
                    @error('csv_file')
                        <p class="text-error text-xs font-semibold -mt-3">{{ $message }}</p>
                    @enderror
                    <div class="flex gap-3 justify-end mt-4">
                        <button type="button" @click="showImportModal = false; selectedImportFileName = ''" class="px-5 py-2.5 rounded-xl font-bold text-sm text-on-surface-variant hover:bg-surface-container-low transition-colors">Batal</button>
                        <button type="submit" class="bg-primary text-on-primary px-6 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:bg-primary-dim transition-colors">Mulai Import</button>
                    </div>
                </form>
            </div>
        </div>
    </template>

    @php
        $pendingConfirmationLabel = \App\Support\PendingConfirmation::LABEL;
        $pendingConfirmationProductId = $categories->firstWhere('name', $pendingConfirmationLabel)?->id;
        $otherNeedsLabel = \App\Models\NeedsCategory::OTHER_OPTION_LABEL;
        $otherNeedsProductId = $categories->firstWhere('name', $otherNeedsLabel)?->id;
    @endphp

    {{-- Create Modal --}}
    <template x-teleport="body">
        <div x-show="showCreateModal" x-cloak class="consultation-modal-backdrop fixed inset-0 z-[60] flex flex-col items-center justify-end sm:justify-center sm:p-4"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
            
            <div class="modal-surface-glow bg-surface-container-lowest w-full sm:max-w-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[90vh] sm:max-h-[85vh] rounded-t-3xl sm:rounded-t-2xl animate-fade-in"
                 x-transition:enter="transition ease-out duration-300 delay-75"
                 x-transition:enter-start="opacity-0 translate-y-10"
                 x-transition:enter-end="opacity-100 translate-y-0">
                 
                {{-- Modal Header --}}
                <div class="px-6 py-5 border-b border-surface-container-low shrink-0 flex justify-between items-center bg-surface-container-lowest sm:rounded-t-2xl rounded-t-3xl z-10 sticky top-0">
                    <div>
                        <h3 class="font-extrabold text-on-surface font-headline text-xl">Tambah Konsultasi Baru</h3>
                        <p class="text-xs text-on-surface-variant font-medium mt-0.5">Isi form untuk menambahkan data lead klien.</p>
                    </div>
                    <button @click="showCreateModal = false" class="w-8 h-8 rounded-full bg-surface-container hover:bg-error/10 hover:text-error text-on-surface-variant flex items-center justify-center transition-colors">
                        <x-icon name="close" class="w-[18px] h-[18px]" />
                    </button>
                </div>

                {{-- Modal Body (Scrollable) --}}
                <div class="p-6 overflow-y-auto scrollbar-thin scrollbar-thumb-surface-container flex-1">
                    @if($errors->any() && old('client_name'))
                    <div class="bg-error/10 text-error px-4 py-3 rounded-xl text-sm font-medium mb-6 animate-fade-in">
                        <div class="flex items-center gap-2 mb-1">
                            <x-icon name="error" class="w-4 h-4" />
                            <span class="font-bold">Terdapat kesalahan:</span>
                        </div>
                        <ul class="list-disc list-inside text-xs space-y-0.5 opacity-90 pl-6">
                            @foreach($errors->all() as $error)
                            <li>{{ $error }}</li>
                            @endforeach
                        </ul>
                    </div>
                    @endif

                    <form method="POST" id="form-create-lead" action="{{ route('consultations.store') }}" class="space-y-6 sm:space-y-8">
                        @csrf

                        {{-- Auto ID --}}
                        <div class="space-y-2">
                            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">ID Konsultasi (Otomatis)</label>
                            <div class="bg-surface-container-low rounded-xl px-4 py-3 text-sm font-mono font-bold text-primary shadow-inner border border-surface-container text-center sm:text-left"
                                 id="preview-consultation-id">
                                {{ $newId }}
                            </div>
                        </div>

                        {{-- Client Name + Phone --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="space-y-2">
                                <label for="client_name" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Klien <span class="text-error">*</span></label>
                                <input type="text" id="client_name" name="client_name" value="{{ old('client_name') }}" maxlength="100"
                                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-outline-variant shadow-inner font-bold"
                                       placeholder="Nama lengkap klien" required />
                            </div>
                            <div class="space-y-2">
                                <label for="phone" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">No. Telepon/WA <span class="text-error">*</span></label>
                                <div x-data="phoneInputState(@js(old('phone')))" x-init="init()">
                                    <div class="phone-input-shell" :class="{ 'phone-input-shell--focused': focused }">
                                        <div class="phone-input-shell__prefix">
                                            <x-icon name="call" class="w-4 h-4" />
                                            <span>+62</span>
                                        </div>
                                        <div class="phone-input-shell__body">
                                            <input type="hidden" name="phone" :value="submittedValue()">
                                            <input type="tel" id="phone" x-model="core" maxlength="18" inputmode="numeric" autocomplete="tel-national"
                                                   @input="onInput($event.target.value)"
                                                   @focus="focused = true"
                                                   @blur="focused = false; core = window.formatIndonesiaPhoneCore(core)"
                                                   class="phone-input-shell__field"
                                                   placeholder="831-3755-4972" required />
                                        </div>
                                    </div>
                                    <p class="phone-input-shell__hint">Nomor otomatis dirapikan ke format +62 yang konsisten.</p>
                                </div>
                            </div>
                        </div>

                        {{-- Province + City (with auto-fill) --}}
                        <div x-data="modalCityAutoFill(@js(old('city', '')), @js(old('province', '')), @js(old('district', '')))" class="space-y-5 sm:space-y-6">
                            <div class="flex items-center justify-between gap-3 rounded-2xl border border-primary/20 bg-primary/10 px-4 py-3">
                                <div>
                                    <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Quick Action Wilayah</div>
                                    <p class="mt-1 text-xs text-on-surface-variant">Isi provinsi, kota, dan kecamatan sekaligus dengan status belum terkonfirmasi.</p>
                                </div>
                                <button type="button"
                                        @click="setPendingConfirmation(@js($pendingConfirmationLabel))"
                                        class="inline-flex shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-surface px-4 py-2.5 text-xs font-bold text-primary shadow-sm transition hover:bg-primary/10">
                                    Belum Ada Konfirmasi
                                </button>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                                <div class="space-y-2">
                                    <label for="modal_province" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">
                                        Provinsi Domisili
                                        <span x-show="loading" class="inline-flex items-center gap-1 text-primary ml-1">
                                            <svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                            <span class="text-[9px]">Mencari...</span>
                                        </span>
                                    </label>
                                    <div x-data="searchableOptions(@js($provinces))"
                                         @click.outside="close()"
                                         @keydown.escape.prevent.stop="close()"
                                         class="relative">
                                        <input type="hidden" name="province" :value="province">
                                        <button type="button"
                                                id="modal_province"
                                                @click="toggle()"
                                                class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                                :class="open ? 'ring-2 ring-primary/20' : ''"
                                                :aria-expanded="open.toString()"
                                                aria-haspopup="listbox">
                                            <span class="block truncate"
                                                  :class="province ? 'font-medium text-on-surface' : 'font-medium text-outline-variant'"
                                                  x-text="province || 'Pilih Provinsi...'"></span>
                                        </button>
                                        <button x-show="province"
                                                x-cloak
                                                type="button"
                                                @click.stop="province = ''; close()"
                                                class="absolute right-10 top-1/2 -translate-y-1/2 rounded-md p-1 text-outline-variant transition hover:bg-surface-container hover:text-on-surface"
                                                aria-label="Kosongkan provinsi">
                                            <x-icon name="close" class="w-4 h-4" />
                                        </button>
                                        <x-icon name="expand_more"
                                                class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                                                x-bind:class="open ? 'rotate-180' : ''" />

                                        <div x-show="open"
                                             x-cloak
                                             x-transition:enter="transition ease-out duration-150"
                                             x-transition:enter-start="opacity-0 -translate-y-1"
                                             x-transition:enter-end="opacity-100 translate-y-0"
                                             class="app-select-panel absolute left-0 right-0 top-full z-[70] mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
                                            <div class="border-b border-surface-container-low p-3">
                                                <input x-ref="searchInput"
                                                       type="text"
                                                       x-model="search"
                                                       class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm shadow-inner focus:ring-2 focus:ring-primary/20"
                                                       placeholder="Cari provinsi..."
                                                       autocomplete="off">
                                            </div>
                                            <div class="max-h-60 overflow-y-auto p-1.5">
                                                <template x-if="filteredOptions().length === 0">
                                                    <div class="px-4 py-3 text-sm text-outline-variant">Provinsi tidak ditemukan.</div>
                                                </template>
                                                <template x-for="option in filteredOptions()" :key="option.value">
                                                    <button type="button"
                                                            @mousedown.prevent="province = option.value; close()"
                                                            class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                                        <span class="truncate font-semibold" x-text="option.label"></span>
                                                        <x-icon name="check"
                                                                class="h-4 w-4 text-primary"
                                                                x-show="province === option.value"></x-icon>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-2 relative">
                                    <label for="modal_city" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kota / Kabupaten</label>
                                    <input type="text" id="modal_city" name="city" value="{{ old('city') }}" x-model="city" maxlength="100"
                                           @input="onCityInput()" @blur="setTimeout(() => showCitySuggestions = false, 200)" @focus="onCityInput()"
                                           class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-outline-variant shadow-inner font-medium"
                                           placeholder="Ketik nama kota..." autocomplete="off" />
                                    {{-- Autocomplete Dropdown --}}
                                    <div x-show="showCitySuggestions && citySuggestions.length > 0" x-cloak
                                         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                         class="absolute top-full left-0 right-0 mt-1 bg-surface-container-lowest border border-surface-container-low rounded-xl shadow-xl z-50 max-h-48 overflow-y-auto divide-y divide-surface-container-low/50">
                                        <template x-for="s in citySuggestions" :key="s.city">
                                            <button type="button" @mousedown.prevent="selectCity(s)" class="w-full text-left px-4 py-2.5 text-sm hover:bg-primary/5 hover:text-primary transition-colors flex items-center justify-between gap-2 cursor-pointer">
                                                <span class="font-bold truncate" x-text="s.city"></span>
                                                <span class="text-[10px] text-outline-variant shrink-0 bg-surface-container-low px-2 py-0.5 rounded-md" x-text="s.province"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            {{-- Kecamatan + Alamat Lengkap --}}
                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                                <div class="space-y-2 relative">
                                    <label for="modal_district" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kecamatan <span class="text-outline-variant font-medium normal-case">(opsional)</span></label>
                                    <input type="text" id="modal_district" name="district" value="{{ old('district') }}" x-model="district" maxlength="100"
                                           @input="onDistrictInput()" @blur="setTimeout(() => showDistrictSuggestions = false, 200)" @focus="onDistrictInput()"
                                           class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-outline-variant shadow-inner font-medium"
                                           placeholder="Nama kecamatan" />
                                    <div x-show="showDistrictSuggestions && districtSuggestions.length > 0" x-cloak
                                         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                         class="absolute top-full left-0 right-0 mt-1 bg-surface-container-lowest border border-surface-container-low rounded-xl shadow-xl z-50 max-h-48 overflow-y-auto divide-y divide-surface-container-low/50">
                                        <template x-for="item in districtSuggestions" :key="`${item.district}-${item.city}`">
                                            <button type="button" @mousedown.prevent="selectDistrict(item)" class="w-full text-left px-4 py-2.5 text-sm hover:bg-primary/5 hover:text-primary transition-colors cursor-pointer">
                                                <div class="font-bold truncate" x-text="item.district"></div>
                                                <div class="mt-0.5 text-[10px] text-outline-variant" x-text="`${item.city} • ${item.province}`"></div>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label for="modal_address" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Alamat Lengkap <span class="text-outline-variant font-medium normal-case">(opsional)</span></label>
                                    <textarea id="modal_address" name="address" rows="2" maxlength="500"
                                              class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant font-medium"
                                              placeholder="Masukkan alamat lengkap">{{ old('address') }}</textarea>
                                </div>
                            </div>
                        </div>

                        {{-- Account Selection (Super Admin Only) --}}
                        @if(auth()->user()->isSuperAdmin())
                        <div class="space-y-2">
                            <label for="modal_account_id" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Akun Interior <span class="text-error">*</span></label>
                            <div x-data="searchableSelect(@js($accounts->map(fn($account) => ['value' => (string) $account->id, 'label' => $account->name])->values()), @js(old('account_id', '')), 'updatePreviewId')"
                                 @click.outside="close()"
                                 @keydown.escape.prevent.stop="close()"
                                 class="relative">
                                <input type="hidden" name="account_id" :value="selected" required>
                                <button type="button"
                                        id="modal_account_id"
                                        @click="toggle()"
                                        class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        :class="open ? 'ring-2 ring-primary/20' : ''"
                                        :aria-expanded="open.toString()"
                                        aria-haspopup="listbox">
                                    <span class="block truncate"
                                          :class="selected ? 'font-bold text-primary' : 'font-bold text-outline-variant'"
                                          x-text="selectedLabel('Pilih Akun...')"></span>
                                </button>
                                <button x-show="selected"
                                        x-cloak
                                        type="button"
                                        @click.stop="clear()"
                                        class="absolute right-10 top-1/2 -translate-y-1/2 rounded-md p-1 text-outline-variant transition hover:bg-surface-container hover:text-on-surface"
                                        aria-label="Kosongkan akun">
                                    <x-icon name="close" class="w-4 h-4" />
                                </button>
                                <x-icon name="expand_more"
                                        class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                                        x-bind:class="open ? 'rotate-180' : ''" />
                                <div x-show="open"
                                     x-cloak
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 -translate-y-1"
                                     x-transition:enter-end="opacity-100 translate-y-0"
                                     class="app-select-panel absolute left-0 right-0 top-full z-[70] mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
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
                                                    class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                                <span class="truncate font-semibold" x-text="option.label"></span>
                                                <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @else
                        <input type="hidden" name="account_id" value="{{ auth()->user()->account_id }}" />
                        @endif

                        @php
                            $selectedProductIds = collect(old('needs_category_ids', old('needs_category_id') ? [old('needs_category_id')] : []))
                                ->map(fn ($value) => (string) $value)
                                ->all();
                            $oldProductDetails = old('product_details');
                        @endphp

                        {{-- Product + Status --}}
                        <div class="space-y-5 sm:space-y-6"
                             x-data="{
                                productOptions: @js($categories->map(fn($category) => ['id' => (string) $category->id, 'name' => $category->name])->values()),
                                selectedProductIds: @js($selectedProductIds),
                                pendingConfirmationProductId: @js($pendingConfirmationProductId ? (string) $pendingConfirmationProductId : null),
                                otherNeedsProductId: @js($otherNeedsProductId ? (string) $otherNeedsProductId : null),
                                normalizeSelectedProducts(value) {
                                    const ids = [...new Set((Array.isArray(value) ? value : [value]).map((item) => String(item ?? '')).filter(Boolean))];
                                    const lastSelectedId = ids[ids.length - 1] ?? null;

                                    const isPendingLast = this.pendingConfirmationProductId && lastSelectedId === this.pendingConfirmationProductId;
                                    const isOtherLast = this.otherNeedsProductId && lastSelectedId === this.otherNeedsProductId;

                                    if (isPendingLast) return [this.pendingConfirmationProductId];
                                    if (isOtherLast) return [this.otherNeedsProductId];

                                    return ids.filter(id => id !== this.pendingConfirmationProductId && id !== this.otherNeedsProductId);
                                },
                                setPendingConfirmationProduct() {
                                    if (!this.pendingConfirmationProductId) {
                                        return;
                                    }

                                    this.selectedProductIds = [this.pendingConfirmationProductId];
                                },
                                shouldShowOtherDetails() {
                                    return !!this.otherNeedsProductId && this.selectedProductIds.includes(this.otherNeedsProductId);
                                },
                                isProductSelected(id) {
                                    return this.selectedProductIds.includes(String(id));
                                },
                                selectedProducts() {
                                    return this.selectedProductIds
                                        .map((id) => this.productOptions.find((option) => option.id === String(id)))
                                        .filter(Boolean);
                                }
                             }"
                             x-init="
                                if (!selectedProductIds.length && pendingConfirmationProductId) {
                                    selectedProductIds = [pendingConfirmationProductId];
                                }
                                selectedProductIds = normalizeSelectedProducts(selectedProductIds);
                                $watch('selectedProductIds', (value) => {
                                    const normalized = normalizeSelectedProducts(value);
                                    if (JSON.stringify(normalized) !== JSON.stringify(value)) {
                                        selectedProductIds = normalized;
                                    }
                                });
                             ">
                            <div class="space-y-3">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 px-1">
                                    <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Nama Produk <span class="text-error">*</span></label>
                                    <div class="flex items-center gap-3 flex-wrap">
                                        @if($pendingConfirmationProductId)
                                    <button type="button"
                                            @click="setPendingConfirmationProduct()"
                                            class="inline-flex items-center justify-center rounded-xl border border-primary/20 bg-primary/10 px-3 py-2 text-[11px] font-bold text-primary transition hover:bg-primary/15">
                                        Pilih Belum Ada Konfirmasi
                                    </button>
                                        @endif
                                        <span class="text-[11px] text-outline-variant">Boleh pilih lebih dari satu produk.</span>
                                    </div>
                                </div>
                                <div class="rounded-lg border border-primary/15 bg-primary/5 px-3 py-2.5">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary/80">Produk Terpilih</span>
                                        <span class="text-[11px] text-outline-variant" x-text="selectedProducts().length ? `${selectedProducts().length} jenis dipilih` : 'Belum ada pilihan'"></span>
                                    </div>
                                    <div class="mt-2.5 flex flex-wrap gap-1.5" x-show="selectedProducts().length" x-cloak>
                                        <template x-for="product in selectedProducts()" :key="product.id">
                                            <span class="product-picked-chip" x-text="product.name"></span>
                                        </template>
                                    </div>
                                    <p class="mt-2 text-[11px] leading-relaxed text-outline-variant" x-show="!selectedProducts().length" x-cloak>
                                        Pilih satu atau beberapa produk. Semua pilihan akan tersimpan untuk satu klien.
                                    </p>
                                </div>

                                <div class="product-picker-panel">
                                    <div class="product-picker-grid">
                                    @foreach($categories as $category)
                                    @php
                                        $isPending = $category->name === \App\Support\PendingConfirmation::LABEL;
                                        $isOther = $category->name === \App\Models\NeedsCategory::OTHER_OPTION_LABEL;
                                        $labelColor = '';
                                        if ($isPending) $labelColor = 'text-amber-600 dark:text-amber-400 font-bold';
                                        elseif ($isOther) $labelColor = 'text-sky-600 dark:text-sky-400 font-bold';
                                    @endphp
                                    <label class="product-picker-item"
                                           :class="{ 'product-picker-item--selected': isProductSelected('{{ $category->id }}') }">
                                        <input type="checkbox" name="needs_category_ids[]" value="{{ $category->id }}" class="peer sr-only" x-model="selectedProductIds"
                                               {{ in_array((string) $category->id, $selectedProductIds, true) ? 'checked' : '' }}>
                                        <span class="product-picker-item__label {{ $labelColor }}">
                                            {{ $category->name }}
                                        </span>
                                        <span class="product-picker-item__check"
                                              :class="{ 'product-picker-item__check--selected': isProductSelected('{{ $category->id }}') }">
                                            <x-icon name="check" class="product-picker-item__check-icon" />
                                        </span>
                                    </label>
                                    @endforeach
                                    </div>
                                </div>
                            </div>

                            <div x-show="shouldShowOtherDetails()"
                                 x-cloak
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 translate-y-2"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 class="space-y-2">
                                <label for="modal_product_details" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Detail Keterangan <span class="text-error">*</span></label>
                                <textarea id="modal_product_details" name="product_details" rows="3" maxlength="1500"
                                          x-bind:required="shouldShowOtherDetails()"
                                          x-bind:disabled="!shouldShowOtherDetails()"
                                          class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant leading-relaxed font-medium"
                                          placeholder="Jelaskan kebutuhan produk untuk pilihan Lain-lain...">{{ $oldProductDetails }}</textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                                <div class="space-y-2">
                                    <label for="modal_status_category_id" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Status Prospek <span class="text-error">*</span></label>
                                    <div x-data="searchableSelect(@js($statuses->map(fn($status) => ['value' => (string) $status->id, 'label' => $status->name])->values()), @js(old('status_category_id', '')))"
                                         @click.outside="close()"
                                         @keydown.escape.prevent.stop="close()"
                                         class="relative">
                                        <input type="hidden" name="status_category_id" :value="selected" required>
                                        <button type="button"
                                                id="modal_status_category_id"
                                                @click="toggle()"
                                                class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                                :class="open ? 'ring-2 ring-primary/20' : ''"
                                                :aria-expanded="open.toString()"
                                                aria-haspopup="listbox">
                                            <span class="block truncate"
                                                  :class="selected ? 'font-bold text-on-surface' : 'font-bold text-outline-variant'"
                                                  x-text="selectedLabel('Pilih Status...')"></span>
                                        </button>
                                        <button x-show="selected" x-cloak type="button" @click.stop="clear()"
                                                class="absolute right-10 top-1/2 -translate-y-1/2 rounded-md p-1 text-outline-variant transition hover:bg-surface-container hover:text-on-surface"
                                                aria-label="Kosongkan status">
                                            <x-icon name="close" class="w-4 h-4" />
                                        </button>
                                        <x-icon name="expand_more" class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                                        <div x-show="open" x-cloak
                                             x-transition:enter="transition ease-out duration-150"
                                             x-transition:enter-start="opacity-0 -translate-y-1"
                                             x-transition:enter-end="opacity-100 translate-y-0"
                                             class="app-select-panel absolute left-0 right-0 top-full z-[70] mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
                                            <div class="border-b border-surface-container-low p-3">
                                                <input x-ref="searchInput" type="text" x-model="search"
                                                       class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm shadow-inner focus:ring-2 focus:ring-primary/20"
                                                       placeholder="Cari status..." autocomplete="off">
                                            </div>
                                            <div class="max-h-60 overflow-y-auto p-1.5">
                                                <template x-if="filteredOptions().length === 0">
                                                    <div class="px-4 py-3 text-sm text-outline-variant">Status tidak ditemukan.</div>
                                                </template>
                                                <template x-for="option in filteredOptions()" :key="option.value">
                                                    <button type="button" @mousedown.prevent="setSelected(option.value)"
                                                            class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                                        <span class="truncate font-semibold" x-text="option.label"></span>
                                                        <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Date + Notes --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label for="modal_consultation_date" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Tanggal Konsultasi Pertama</label>
                                <input type="date" id="modal_consultation_date" name="consultation_date" value="{{ old('consultation_date', now()->format('Y-m-d')) }}"
                                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-medium" />
                            </div>
                            <div class="hidden md:block"></div>
                        </div>

                        <div class="space-y-2">
                            <label for="modal_notes" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Keterangan Follow-Up Awal</label>
                            <textarea id="modal_notes" name="notes" rows="4" maxlength="1000"
                                      class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant leading-relaxed font-medium"
                                      placeholder="Hasil brief awal atau info tambahan klien...">{{ old('notes') }}</textarea>
                        </div>
                    </form>
                </div>

                {{-- Modal Footer --}}
                <div class="px-6 py-4 bg-surface-container-lowest border-t border-surface-container-low shrink-0 flex flex-col sm:flex-row justify-end gap-3 rounded-b-2xl">
                    <button type="button" @click="showCreateModal = false" class="px-6 py-2.5 rounded-xl font-bold text-sm text-on-surface-variant hover:bg-surface-container-low transition-colors w-full sm:w-auto">Batal</button>
                    <button type="submit" form="form-create-lead" class="bg-primary text-on-primary px-8 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:bg-primary-dim transition-colors flex items-center justify-center gap-2 w-full sm:w-auto">
                        <x-icon name="save" class="w-4 h-4" />
                        <span>Simpan Data Konsultasi</span>
                    </button>
                </div>
            </div>
        </div>
    </template>

    {{-- Edit Modal --}}
    <template x-teleport="body">
        <div x-show="showEditModal" x-cloak class="consultation-modal-backdrop fixed inset-0 z-[60] flex flex-col items-center justify-end sm:justify-center sm:p-4"
             x-transition:enter="transition ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="transition ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0">
            
            <div class="modal-surface-glow bg-surface-container-lowest w-full sm:max-w-2xl sm:rounded-2xl shadow-2xl flex flex-col max-h-[90vh] sm:max-h-[85vh] rounded-t-3xl sm:rounded-t-2xl animate-fade-in"
                 x-transition:enter="transition ease-out duration-300 delay-75"
                 x-transition:enter-start="opacity-0 translate-y-10"
                 x-transition:enter-end="opacity-100 translate-y-0">
                  
                {{-- Modal Header --}}
                <div class="px-6 py-5 border-b border-surface-container-low shrink-0 flex justify-between items-center bg-surface-container-lowest sm:rounded-t-2xl rounded-t-3xl z-10 sticky top-0">
                    <div>
                        <h3 class="font-extrabold text-on-surface font-headline text-xl">Edit Konsultasi</h3>
                        <p class="text-xs text-on-surface-variant font-medium mt-0.5">Ubah data lead klien.</p>
                    </div>
                    <button @click="showEditModal = false" class="w-8 h-8 rounded-full bg-surface-container hover:bg-error/10 hover:text-error text-on-surface-variant flex items-center justify-center transition-colors">
                        <x-icon name="close" class="w-[18px] h-[18px]" />
                    </button>
                </div>

                {{-- Modal Body (Scrollable) --}}
                <div class="p-6 overflow-y-auto scrollbar-thin scrollbar-thumb-surface-container flex-1">
                    <form method="POST" id="form-edit-lead" :action="buildConsultationUpdateUrl(editData.id)" class="space-y-6">
                        @csrf
                        @method('PUT')
                        <input type="hidden" name="id" :value="editData.id">

                        {{-- Consultation ID (Read-only) --}}
                        <div class="space-y-2">
                            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">ID Konsultasi</label>
                            <div class="bg-surface-container-low rounded-xl px-4 py-3 text-sm font-mono font-bold text-primary shadow-inner border border-surface-container text-center sm:text-left">
                                <span x-text="editData.consultation_id"></span>
                            </div>
                        </div>

                        {{-- Client Name + Phone --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-5">
                            <div class="space-y-2">
                                <label for="edit_client_name" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Klien <span class="text-error">*</span></label>
                                <input type="text" id="edit_client_name" name="client_name" x-model="editData.client_name" maxlength="100"
                                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-outline-variant shadow-inner font-bold"
                                       placeholder="Nama lengkap klien" required />
                            </div>
                            <div class="space-y-2">
                                <label for="edit_phone" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">No. Telepon/WA <span class="text-error">*</span></label>
                                <div x-data="phoneInputState(editData.phone)"
                                     x-init="init(); editData.phone = submittedValue(); $watch('editData.phone', value => syncFromExternal(value)); $watch('core', () => editData.phone = submittedValue())">
                                    <div class="phone-input-shell" :class="{ 'phone-input-shell--focused': focused }">
                                        <div class="phone-input-shell__prefix">
                                            <x-icon name="call" class="w-4 h-4" />
                                            <span>+62</span>
                                        </div>
                                        <div class="phone-input-shell__body">
                                            <input type="hidden" name="phone" :value="submittedValue()">
                                            <input type="tel" id="edit_phone" x-model="core" maxlength="18" inputmode="numeric" autocomplete="tel-national"
                                                   @input="onInput($event.target.value)"
                                                   @focus="focused = true"
                                                   @blur="focused = false; core = window.formatIndonesiaPhoneCore(core)"
                                                   class="phone-input-shell__field"
                                                   placeholder="831-3755-4972" required />
                                        </div>
                                    </div>
                                    <p class="phone-input-shell__hint">Field ini otomatis mengubah nomor lokal menjadi format WhatsApp nasional.</p>
                                </div>
                            </div>
                        </div>

                        {{-- Province + City --}}
                        <div x-data="modalLocationAutoFill()" x-init="$watch('city', value => editData.city = value); $watch('province', value => editData.province = value); $watch('district', value => editData.district = value); city = editData.city || ''; province = editData.province || ''; district = editData.district || ''; $watch('editData.city', value => { if (value !== city) city = value || ''; }); $watch('editData.province', value => { if (value !== province) province = value || ''; }); $watch('editData.district', value => { if (value !== district) district = value || ''; });" class="space-y-5 sm:space-y-6">
                            <div class="flex items-center justify-between gap-3 rounded-2xl border border-primary/20 bg-primary/10 px-4 py-3">
                                <div>
                                    <div class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary">Quick Action Wilayah</div>
                                    <p class="mt-1 text-xs text-on-surface-variant">Gunakan tombol ini jika provinsi, kota, dan kecamatan memang belum ada konfirmasi.</p>
                                </div>
                                <button type="button"
                                        @click="setPendingConfirmation(@js($pendingConfirmationLabel))"
                                        class="inline-flex shrink-0 items-center justify-center rounded-xl border border-primary/20 bg-surface px-4 py-2.5 text-xs font-bold text-primary shadow-sm transition hover:bg-primary/10">
                                    Belum Ada Konfirmasi
                                </button>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                                <div class="space-y-2">
                                    <label for="edit_province" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">
                                        Provinsi
                                        <span x-show="loading" class="inline-flex items-center gap-1 text-primary ml-1">
                                            <svg class="animate-spin h-3 w-3" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4" fill="none"/><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4z"/></svg>
                                            <span class="text-[9px]">Mencari...</span>
                                        </span>
                                    </label>
                                    <div x-data="searchableOptions(@js($provinces))"
                                         @click.outside="close()"
                                         @keydown.escape.prevent.stop="close()"
                                         class="relative">
                                        <input type="hidden" name="province" :value="province">
                                        <button type="button"
                                                id="edit_province"
                                                @click="toggle()"
                                                class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                                :class="open ? 'ring-2 ring-primary/20' : ''"
                                                :aria-expanded="open.toString()"
                                                aria-haspopup="listbox">
                                            <span class="block truncate"
                                                  :class="province ? 'font-medium text-on-surface' : 'font-medium text-outline-variant'"
                                                  x-text="province || 'Pilih Provinsi...'"></span>
                                        </button>
                                        <button x-show="province"
                                                x-cloak
                                                type="button"
                                                @click.stop="province = ''; close()"
                                                class="absolute right-10 top-1/2 -translate-y-1/2 rounded-md p-1 text-outline-variant transition hover:bg-surface-container hover:text-on-surface"
                                                aria-label="Kosongkan provinsi">
                                            <x-icon name="close" class="w-4 h-4" />
                                        </button>
                                        <x-icon name="expand_more"
                                                class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                                                x-bind:class="open ? 'rotate-180' : ''" />

                                        <div x-show="open"
                                             x-cloak
                                             x-transition:enter="transition ease-out duration-150"
                                             x-transition:enter-start="opacity-0 -translate-y-1"
                                             x-transition:enter-end="opacity-100 translate-y-0"
                                             class="app-select-panel absolute left-0 right-0 top-full z-[70] mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
                                            <div class="border-b border-surface-container-low p-3">
                                                <input x-ref="searchInput"
                                                       type="text"
                                                       x-model="search"
                                                       class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm shadow-inner focus:ring-2 focus:ring-primary/20"
                                                       placeholder="Cari provinsi..."
                                                       autocomplete="off">
                                            </div>
                                            <div class="max-h-60 overflow-y-auto p-1.5">
                                                <template x-if="filteredOptions().length === 0">
                                                    <div class="px-4 py-3 text-sm text-outline-variant">Provinsi tidak ditemukan.</div>
                                                </template>
                                                <template x-for="option in filteredOptions()" :key="option.value">
                                                    <button type="button"
                                                            @mousedown.prevent="province = option.value; close()"
                                                            class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                                        <span class="truncate font-semibold" x-text="option.label"></span>
                                                        <x-icon name="check"
                                                                class="h-4 w-4 text-primary"
                                                                x-show="province === option.value"></x-icon>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                                <div class="space-y-2 relative">
                                    <label for="edit_city" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kota / Kabupaten</label>
                                    <input type="text" id="edit_city" name="city" x-model="city" maxlength="100"
                                           @input="onCityInput()" @blur="setTimeout(() => showCitySuggestions = false, 200)" @focus="onCityInput()"
                                           class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-outline-variant shadow-inner font-medium"
                                           placeholder="Ketik nama kota..." autocomplete="off" />
                                    <div x-show="showCitySuggestions && citySuggestions.length > 0" x-cloak
                                         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                         class="absolute top-full left-0 right-0 mt-1 bg-surface-container-lowest border border-surface-container-low rounded-xl shadow-xl z-50 max-h-48 overflow-y-auto divide-y divide-surface-container-low/50">
                                        <template x-for="s in citySuggestions" :key="s.city">
                                            <button type="button" @mousedown.prevent="selectCity(s)" class="w-full text-left px-4 py-2.5 text-sm hover:bg-primary/5 hover:text-primary transition-colors flex items-center justify-between gap-2 cursor-pointer">
                                                <span class="font-bold truncate" x-text="s.city"></span>
                                                <span class="text-[10px] text-outline-variant shrink-0 bg-surface-container-low px-2 py-0.5 rounded-md" x-text="s.province"></span>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                                <div class="space-y-2 relative">
                                    <label for="edit_district" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kecamatan <span class="text-outline-variant font-medium normal-case">(opsional)</span></label>
                                    <input type="text" id="edit_district" name="district" x-model="district" maxlength="100"
                                           @input="onDistrictInput()" @blur="setTimeout(() => showDistrictSuggestions = false, 200)" @focus="onDistrictInput()"
                                           class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 placeholder:text-outline-variant shadow-inner font-medium"
                                           placeholder="Nama kecamatan" />
                                    <div x-show="showDistrictSuggestions && districtSuggestions.length > 0" x-cloak
                                         x-transition:enter="transition ease-out duration-100" x-transition:enter-start="opacity-0 -translate-y-1" x-transition:enter-end="opacity-100 translate-y-0"
                                         class="absolute top-full left-0 right-0 mt-1 bg-surface-container-lowest border border-surface-container-low rounded-xl shadow-xl z-50 max-h-48 overflow-y-auto divide-y divide-surface-container-low/50">
                                        <template x-for="item in districtSuggestions" :key="`${item.district}-${item.city}`">
                                            <button type="button" @mousedown.prevent="selectDistrict(item)" class="w-full text-left px-4 py-2.5 text-sm hover:bg-primary/5 hover:text-primary transition-colors cursor-pointer">
                                                <div class="font-bold truncate" x-text="item.district"></div>
                                                <div class="mt-0.5 text-[10px] text-outline-variant" x-text="`${item.city} • ${item.province}`"></div>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                                <div class="space-y-2">
                                    <label for="edit_address" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Alamat Lengkap <span class="text-outline-variant font-medium normal-case">(opsional)</span></label>
                                    <textarea id="edit_address" name="address" rows="2" x-model="editData.address" maxlength="500"
                                              class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant font-medium"
                                              placeholder="Masukkan alamat lengkap"></textarea>
                                </div>
                            </div>
                        </div>

                        {{-- Account Selection (Super Admin Only) --}}
                        @if(auth()->user()->isSuperAdmin())
                        <div class="space-y-2">
                            <label for="edit_account_id" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Akun <span class="text-error">*</span></label>
                            <div x-data="searchableSelect(@js($accounts->map(fn($account) => ['value' => (string) $account->id, 'label' => $account->name])->values()))"
                                 x-init="$watch('selected', value => { editData.account_id = value; updatePreviewId(value); })"
                                 x-effect="if (selected !== String(editData.account_id || '')) selected = String(editData.account_id || '')"
                                 @click.outside="close()"
                                 @keydown.escape.prevent.stop="close()"
                                 class="relative">
                                <input type="hidden" name="account_id" :value="selected" required>
                                <button type="button"
                                        id="edit_account_id"
                                        @click="toggle()"
                                        class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                        :class="open ? 'ring-2 ring-primary/20' : ''"
                                        :aria-expanded="open.toString()"
                                        aria-haspopup="listbox">
                                    <span class="block truncate"
                                          :class="selected ? 'font-bold text-primary' : 'font-bold text-outline-variant'"
                                          x-text="selectedLabel('Pilih Akun...')"></span>
                                </button>
                                <button x-show="selected" x-cloak type="button" @click.stop="clear()"
                                        class="absolute right-10 top-1/2 -translate-y-1/2 rounded-md p-1 text-outline-variant transition hover:bg-surface-container hover:text-on-surface"
                                        aria-label="Kosongkan akun">
                                    <x-icon name="close" class="w-4 h-4" />
                                </button>
                                <x-icon name="expand_more" class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                                <div x-show="open" x-cloak
                                     x-transition:enter="transition ease-out duration-150"
                                     x-transition:enter-start="opacity-0 -translate-y-1"
                                     x-transition:enter-end="opacity-100 translate-y-0"
                                     class="app-select-panel absolute left-0 right-0 top-full z-[70] mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
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
                                                    class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                                <span class="truncate font-semibold" x-text="option.label"></span>
                                                <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                            </button>
                                        </template>
                                    </div>
                                </div>
                            </div>
                        </div>
                        @else
                        <input type="hidden" name="account_id" x-model="editData.account_id" />
                        @endif

                        {{-- Product + Status --}}
                        <div class="space-y-5 sm:space-y-6"
                             x-data="{
                                productOptions: @js($categories->map(fn($category) => ['id' => (string) $category->id, 'name' => $category->name])->values()),
                                selectedProductIds: [],
                                pendingConfirmationProductId: @js($pendingConfirmationProductId ? (string) $pendingConfirmationProductId : null),
                                otherNeedsProductId: @js($otherNeedsProductId ? (string) $otherNeedsProductId : null),
                                normalizeSelectedProducts(value, fallbackValue = null) {
                                    const ids = window.normalizeConsultationCategoryIds(value, fallbackValue);
                                    const lastSelectedId = ids[ids.length - 1] ?? null;

                                    const isPendingLast = this.pendingConfirmationProductId && lastSelectedId === this.pendingConfirmationProductId;
                                    const isOtherLast = this.otherNeedsProductId && lastSelectedId === this.otherNeedsProductId;

                                    if (isPendingLast) return [this.pendingConfirmationProductId];
                                    if (isOtherLast) return [this.otherNeedsProductId];

                                    return ids.filter(id => id !== this.pendingConfirmationProductId && id !== this.otherNeedsProductId);
                                },
                                setPendingConfirmationProduct() {
                                    if (!this.pendingConfirmationProductId) {
                                        return;
                                    }

                                    this.selectedProductIds = [this.pendingConfirmationProductId];
                                },
                                shouldShowOtherDetails() {
                                    return !!this.otherNeedsProductId && this.selectedProductIds.includes(this.otherNeedsProductId);
                                },
                                isProductSelected(id) {
                                    return this.selectedProductIds.includes(String(id));
                                },
                                selectedProducts() {
                                    return this.selectedProductIds
                                        .map((id) => this.productOptions.find((option) => option.id === String(id)))
                                        .filter(Boolean);
                                },
                                syncFromEditData() {
                                    this.selectedProductIds = this.normalizeSelectedProducts(
                                        editData.needs_category_ids,
                                        editData.needs_category_id
                                    );
                                }
                             }"
                             x-init="
                                syncFromEditData();
                                $watch('selectedProductIds', (value) => {
                                    const normalized = normalizeSelectedProducts(value, editData.needs_category_id);
                                    if (JSON.stringify(normalized) !== JSON.stringify(value)) {
                                        selectedProductIds = normalized;
                                        return;
                                    }

                                    editData.needs_category_ids = normalized;
                                    editData.needs_category_id = normalized[0] ?? '';
                                });
                                $watch('showEditModal', (value) => {
                                    if (value) {
                                        syncFromEditData();
                                    }
                                });
                                $watch('editData.id', () => {
                                    if (showEditModal) {
                                        syncFromEditData();
                                    }
                                });
                             ">
                            <div class="space-y-3">
                                <div class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-1 px-1">
                                    <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Nama Produk <span class="text-error">*</span></label>
                                    <div class="flex items-center gap-3 flex-wrap">
                                        @if($pendingConfirmationProductId)
                                        <button type="button"
                                                @click="setPendingConfirmationProduct()"
                                                class="inline-flex items-center justify-center rounded-xl border border-primary/20 bg-primary/10 px-3 py-2 text-[11px] font-bold text-primary transition hover:bg-primary/15">
                                            Pilih Belum Ada Konfirmasi
                                        </button>
                                        @endif
                                        <span class="text-[11px] text-outline-variant">Satu konsumen bisa memilih lebih dari satu produk.</span>
                                    </div>
                                </div>
                                <div class="rounded-xl border border-primary/15 bg-primary/5 px-4 py-3">
                                    <div class="flex items-center justify-between gap-3">
                                        <span class="text-[10px] font-bold uppercase tracking-widest text-primary/80">Produk Terpilih</span>
                                        <span class="text-[11px] text-outline-variant" x-text="selectedProducts().length ? `${selectedProducts().length} jenis dipilih` : 'Belum ada pilihan'"></span>
                                    </div>
                                    <div class="mt-3 flex flex-wrap gap-2" x-show="selectedProducts().length" x-cloak>
                                        <template x-for="product in selectedProducts()" :key="product.id">
                                            <span class="product-picked-chip" x-text="product.name"></span>
                                        </template>
                                    </div>
                                    <p class="mt-2 text-xs text-outline-variant" x-show="!selectedProducts().length" x-cloak>
                                        Pilih satu atau beberapa produk. Semua pilihan akan tersimpan untuk satu klien.
                                    </p>
                                </div>

                                <div class="product-picker-panel">
                                    <div class="product-picker-grid">
                                    @foreach($categories as $category)
                                    @php
                                        $isPending = $category->name === \App\Support\PendingConfirmation::LABEL;
                                        $isOther = $category->name === \App\Models\NeedsCategory::OTHER_OPTION_LABEL;
                                        $labelColor = '';
                                        if ($isPending) $labelColor = 'text-amber-600 dark:text-amber-400 font-bold';
                                        elseif ($isOther) $labelColor = 'text-sky-600 dark:text-sky-400 font-bold';
                                    @endphp
                                    <label class="product-picker-item"
                                           :class="{ 'product-picker-item--selected': isProductSelected('{{ $category->id }}') }">
                                        <input type="checkbox" name="needs_category_ids[]" value="{{ $category->id }}" class="peer sr-only" x-model="selectedProductIds">
                                        <span class="product-picker-item__label {{ $labelColor }}">
                                            {{ $category->name }}
                                        </span>
                                        <span class="product-picker-item__check"
                                              :class="{ 'product-picker-item__check--selected': isProductSelected('{{ $category->id }}') }">
                                            <x-icon name="check" class="product-picker-item__check-icon" />
                                        </span>
                                    </label>
                                    @endforeach
                                    </div>
                                </div>
                            </div>

                            <div x-show="shouldShowOtherDetails()"
                                 x-cloak
                                 x-transition:enter="transition ease-out duration-200"
                                 x-transition:enter-start="opacity-0 translate-y-2"
                                 x-transition:enter-end="opacity-100 translate-y-0"
                                 class="space-y-2">
                                <label for="edit_product_details" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Detail Keterangan <span class="text-error">*</span></label>
                                <textarea id="edit_product_details" name="product_details" rows="3" x-model="editData.product_details" maxlength="1500"
                                          x-bind:required="shouldShowOtherDetails()"
                                          x-bind:disabled="!shouldShowOtherDetails()"
                                          class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant leading-relaxed font-medium"
                                          placeholder="Jelaskan kebutuhan produk untuk pilihan Lain-lain..."></textarea>
                            </div>

                            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                                <div class="space-y-2">
                                    <label for="edit_status_category_id" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Status Prospek <span class="text-error">*</span></label>
                                    <div x-data="searchableSelect(@js($statuses->map(fn($status) => ['value' => (string) $status->id, 'label' => $status->name])->values()))"
                                         x-init="$watch('selected', value => editData.status_category_id = value)"
                                         x-effect="if (selected !== String(editData.status_category_id || '')) selected = String(editData.status_category_id || '')"
                                         @click.outside="close()"
                                         @keydown.escape.prevent.stop="close()"
                                         class="relative">
                                        <input type="hidden" name="status_category_id" :value="selected" required>
                                        <button type="button"
                                                id="edit_status_category_id"
                                                @click="toggle()"
                                                class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                                :class="open ? 'ring-2 ring-primary/20' : ''"
                                                :aria-expanded="open.toString()"
                                                aria-haspopup="listbox">
                                            <span class="block truncate"
                                                  :class="selected ? 'font-bold text-on-surface' : 'font-bold text-outline-variant'"
                                                  x-text="selectedLabel('Pilih Status...')"></span>
                                        </button>
                                        <button x-show="selected" x-cloak type="button" @click.stop="clear()"
                                                class="absolute right-10 top-1/2 -translate-y-1/2 rounded-md p-1 text-outline-variant transition hover:bg-surface-container hover:text-on-surface"
                                                aria-label="Kosongkan status">
                                            <x-icon name="close" class="w-4 h-4" />
                                        </button>
                                        <x-icon name="expand_more" class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                                        <div x-show="open" x-cloak
                                             x-transition:enter="transition ease-out duration-150"
                                             x-transition:enter-start="opacity-0 -translate-y-1"
                                             x-transition:enter-end="opacity-100 translate-y-0"
                                             class="app-select-panel absolute left-0 right-0 top-full z-[70] mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
                                            <div class="border-b border-surface-container-low p-3">
                                                <input x-ref="searchInput" type="text" x-model="search"
                                                       class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm shadow-inner focus:ring-2 focus:ring-primary/20"
                                                       placeholder="Cari status..." autocomplete="off">
                                            </div>
                                            <div class="max-h-60 overflow-y-auto p-1.5">
                                                <template x-if="filteredOptions().length === 0">
                                                    <div class="px-4 py-3 text-sm text-outline-variant">Status tidak ditemukan.</div>
                                                </template>
                                                <template x-for="option in filteredOptions()" :key="option.value">
                                                    <button type="button" @mousedown.prevent="setSelected(option.value)"
                                                            class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                                        <span class="truncate font-semibold" x-text="option.label"></span>
                                                        <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                                    </button>
                                                </template>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>

                        {{-- Date + Notes --}}
                        <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                            <div class="space-y-2">
                                <label for="edit_consultation_date" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Tanggal Konsultasi</label>
                                <input type="date" id="edit_consultation_date" name="consultation_date" x-model="editData.consultation_date"
                                       class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-medium" />
                            </div>
                            <div class="hidden md:block"></div>
                        </div>
                        <div class="space-y-2">
                            <label for="edit_notes" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Keterangan Tambahan</label>
                            <textarea id="edit_notes" name="notes" rows="4" x-model="editData.notes" maxlength="1000"
                                      class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant leading-relaxed font-medium"
                                      placeholder="Tambahkan info follow-up..."></textarea>
                        </div>
                    </form>
                </div>

                {{-- Modal Footer --}}
                <div class="px-6 py-4 bg-surface-container-lowest border-t border-surface-container-low shrink-0 flex flex-col sm:flex-row justify-end gap-3 rounded-b-2xl">
                    <button type="button" @click="showEditModal = false" class="px-6 py-2.5 rounded-xl font-bold text-sm text-on-surface-variant hover:bg-surface-container-low transition-colors w-full sm:w-auto">Batal</button>
                    <button type="submit" form="form-edit-lead" class="bg-primary text-on-primary px-8 py-2.5 rounded-xl font-bold text-sm shadow-lg shadow-primary/20 hover:bg-primary-dim transition-colors flex items-center justify-center gap-2 w-full sm:w-auto">
                        <x-icon name="save" class="w-4 h-4" />
                        <span>Simpan Perubahan</span>
                    </button>
                </div>
            </div>
        </div>
    </template>
</div>

{{-- Filters --}}
@php
    $hasActiveConsultationFilters = request()->hasAny(['search', 'status', 'account', 'start_date', 'end_date', 'month', 'year']);
    $monthOptions = [
        1 => 'Januari',
        2 => 'Februari',
        3 => 'Maret',
        4 => 'April',
        5 => 'Mei',
        6 => 'Juni',
        7 => 'Juli',
        8 => 'Agustus',
        9 => 'September',
        10 => 'Oktober',
        11 => 'November',
        12 => 'Desember',
    ];
    $yearOptions = range(now()->year + 1, 2020);
@endphp
<div x-data="{
        isDesktop: window.innerWidth >= 1280,
        lastViewportMode: window.innerWidth >= 1280 ? 'desktop' : 'mobile',
        manualMobilePreference: null,
        filterOpen: {{ $hasActiveConsultationFilters ? 'true' : 'false' }},
        init() {
            const defaultMobileOpen = {{ $hasActiveConsultationFilters ? 'true' : 'false' }};
            const syncViewport = () => {
                const nextIsDesktop = window.innerWidth >= 1280;
                const nextViewportMode = nextIsDesktop ? 'desktop' : 'mobile';

                if (nextViewportMode === this.lastViewportMode) {
                    this.isDesktop = nextIsDesktop;
                    return;
                }

                this.isDesktop = nextIsDesktop;
                this.lastViewportMode = nextViewportMode;

                if (nextIsDesktop) {
                    this.filterOpen = true;
                    return;
                }

                this.filterOpen = this.manualMobilePreference ?? defaultMobileOpen;
            };

            syncViewport();
            window.addEventListener('resize', syncViewport);
        },
        toggleFilter() {
            if (this.isDesktop) {
                return;
            }

            this.filterOpen = !this.filterOpen;
            this.manualMobilePreference = this.filterOpen;
        }
    }"
    class="w-full max-w-[1020px] bg-surface-container-lowest p-4 sm:p-5 rounded-2xl shadow-sm mb-6 no-print border border-surface-container-low">
    <div class="xl:hidden rounded-2xl border border-surface-container-low bg-surface-container-low/40 px-3.5 py-3 shadow-inner">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <div class="text-[10px] font-extrabold uppercase tracking-[0.22em] text-on-surface-variant opacity-75">Filter Lead</div>
                <p class="mt-1 text-xs leading-5 text-on-surface-variant">Buka saat perlu menyaring data. Panel akan tetap terbuka sampai Anda klik tutup.</p>
            </div>
            <button type="button"
                    @click="toggleFilter()"
                    class="shrink-0 inline-flex min-w-[5.5rem] items-center justify-center gap-1.5 rounded-xl border border-outline-variant/20 bg-surface px-3.5 py-2.5 text-xs font-bold text-on-surface shadow-sm transition hover:border-primary/25 hover:text-primary">
                <span x-text="filterOpen ? 'Tutup' : 'Buka'"></span>
                <x-icon name="expand_more"
                        class="w-4 h-4 transition-transform duration-200"
                        x-bind:class="filterOpen ? 'rotate-180 text-primary' : ''" />
            </button>
        </div>
    </div>

    <div x-show="isDesktop || filterOpen"
         x-transition:enter="transition ease-out duration-220"
         x-transition:enter-start="opacity-0 -translate-y-2"
         x-transition:enter-end="opacity-100 translate-y-0"
         x-transition:leave="transition ease-in duration-180"
         x-transition:leave-start="opacity-100 translate-y-0"
         x-transition:leave-end="opacity-0 -translate-y-2"
         class="mt-4 xl:mt-0">
    <form method="GET" action="{{ route('consultations.index') }}" class="space-y-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 {{ auth()->user()->isSuperAdmin() ? '2xl:grid-cols-4' : '' }} gap-3">
            {{-- Search --}}
            <div class="xl:col-span-1">
                <label class="block text-[10px] font-extrabold text-on-surface-variant uppercase tracking-widest mb-1.5 px-1 opacity-70">Search</label>
                <div class="relative group">
                    <span class="absolute inset-y-0 left-3 flex items-center text-outline-variant group-focus-within:text-primary transition-colors">
                        <x-icon name="search" class="w-[18px] h-[18px]" />
                    </span>
                    <input type="text" name="search" value="{{ request('search') }}"
                           class="w-full bg-surface-container-low border-0 rounded-xl pl-10 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner"
                           placeholder="Nama, telp, ID..." />
                </div>
            </div>

            {{-- Start Date --}}
            <div>
                <label class="block text-[10px] font-extrabold text-on-surface-variant uppercase tracking-widest mb-1.5 px-1 opacity-70">Tgl Mulai</label>
                <div class="relative group">
                    <span class="absolute inset-y-0 left-3 flex items-center text-outline-variant group-focus-within:text-primary transition-colors">
                        <x-icon name="calendar_today" class="w-[18px] h-[18px]" />
                    </span>
                    <input type="date" name="start_date" value="{{ request('start_date') }}"
                           class="w-full bg-surface-container-low border-0 rounded-xl pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner" />
                </div>
            </div>

            {{-- End Date --}}
            <div>
                <label class="block text-[10px] font-extrabold text-on-surface-variant uppercase tracking-widest mb-1.5 px-1 opacity-70">Tgl Akhir</label>
                <div class="relative group">
                    <span class="absolute inset-y-0 left-3 flex items-center text-outline-variant group-focus-within:text-primary transition-colors">
                        <x-icon name="event" class="w-[18px] h-[18px]" />
                    </span>
                    <input type="date" name="end_date" value="{{ request('end_date') }}"
                           class="w-full bg-surface-container-low border-0 rounded-xl pl-10 pr-4 py-2 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner" />
                </div>
            </div>

            {{-- Month Filter --}}
            <div>
                <label class="block text-[10px] font-extrabold text-on-surface-variant uppercase tracking-widest mb-1.5 px-1 opacity-70">Bulan</label>
                <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => 'Semua Bulan']])->concat(collect($monthOptions)->map(fn($monthName, $monthNumber) => ['value' => (string) $monthNumber, 'label' => $monthName])->values())), @js((string) request('month', '')))"
                     @click.outside="close()"
                     @keydown.escape.prevent.stop="close()"
                     class="relative">
                    <input type="hidden" name="month" :value="selected">
                    <button type="button"
                            @click="toggle()"
                            class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-2.5 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                            :class="open ? 'ring-2 ring-primary/20' : ''"
                            :aria-expanded="open.toString()"
                            aria-haspopup="listbox">
                        <span class="block truncate font-semibold text-on-surface-variant"
                              x-text="selectedLabel('Semua Bulan')"></span>
                    </button>
                    <x-icon name="expand_more"
                            class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                            x-bind:class="open ? 'rotate-180 text-primary' : ''" />
                    <div x-show="open"
                         x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="app-select-panel absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest p-2.5 shadow-2xl sm:min-w-[28rem]">
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <template x-for="option in options" :key="option.value">
                                <button type="button"
                                        @mousedown.prevent="setSelected(option.value)"
                                        class="min-h-[42px] rounded-xl px-3 py-2.5 text-center text-sm font-bold transition hover:bg-primary/10 hover:text-primary"
                                        :class="selected === option.value ? 'bg-primary/10 text-primary' : 'text-on-surface'">
                                    <span class="block truncate" x-text="option.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Year Filter --}}
            <div>
                <label class="block text-[10px] font-extrabold text-on-surface-variant uppercase tracking-widest mb-1.5 px-1 opacity-70">Tahun</label>
                <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => 'Semua Tahun']])->concat(collect($yearOptions)->map(fn($yearOption) => ['value' => (string) $yearOption, 'label' => (string) $yearOption])->values())), @js((string) request('year', '')))"
                     @click.outside="close()"
                     @keydown.escape.prevent.stop="close()"
                     class="relative">
                    <input type="hidden" name="year" :value="selected">
                    <button type="button"
                            @click="toggle()"
                            class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-2.5 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                            :class="open ? 'ring-2 ring-primary/20' : ''"
                            :aria-expanded="open.toString()"
                            aria-haspopup="listbox">
                        <span class="block truncate font-semibold text-on-surface-variant"
                              x-text="selectedLabel('Semua Tahun')"></span>
                    </button>
                    <x-icon name="expand_more"
                            class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                            x-bind:class="open ? 'rotate-180 text-primary' : ''" />
                    <div x-show="open"
                         x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                         class="app-select-panel absolute left-0 right-0 top-full z-50 mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest p-2.5 shadow-2xl sm:min-w-[22rem]">
                        <div class="grid grid-cols-2 sm:grid-cols-3 gap-2">
                            <template x-for="option in options" :key="option.value">
                                <button type="button"
                                        @mousedown.prevent="setSelected(option.value)"
                                        class="min-h-[42px] rounded-xl px-3 py-2.5 text-center text-sm font-bold transition hover:bg-primary/10 hover:text-primary"
                                        :class="selected === option.value ? 'bg-primary/10 text-primary' : 'text-on-surface'">
                                    <span class="block truncate" x-text="option.label"></span>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Status Filter --}}
            <div>
                <label class="block text-[10px] font-extrabold text-on-surface-variant uppercase tracking-widest mb-1.5 px-1 opacity-70">Status</label>
                <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => 'Semua Status']])->concat($statuses->map(fn($status) => ['value' => (string) $status->id, 'label' => $status->name])->values())), @js((string) request('status', '')))"
                     @click.outside="close()"
                     @keydown.escape.prevent.stop="close()"
                     class="relative">
                    <input type="hidden" name="status" :value="selected">
                    <button type="button"
                            @click="toggle()"
                            class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-2.5 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                            :class="open ? 'ring-2 ring-primary/20' : ''"
                            :aria-expanded="open.toString()"
                            aria-haspopup="listbox">
                        <span class="block truncate font-semibold text-on-surface-variant"
                              x-text="selectedLabel('Semua Status')"></span>
                    </button>
                    <x-icon name="expand_more"
                            class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                            x-bind:class="open ? 'rotate-180' : ''" />
                    <div x-show="open"
                         x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                        class="app-select-panel absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
                        <div class="border-b border-surface-container-low p-3">
                            <input x-ref="searchInput" type="text" x-model="search"
                                   class="w-full rounded-xl border-0 bg-surface-container-low px-4 py-3 text-sm shadow-inner focus:ring-2 focus:ring-primary/20"
                                   placeholder="Cari status..." autocomplete="off">
                        </div>
                        <div class="max-h-60 overflow-y-auto p-1.5">
                            <template x-if="filteredOptions().length === 0">
                                <div class="px-4 py-3 text-sm text-outline-variant">Status tidak ditemukan.</div>
                            </template>
                            <template x-for="option in filteredOptions()" :key="option.value">
                                <button type="button" @mousedown.prevent="setSelected(option.value)"
                                        class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                    <span class="truncate font-semibold" x-text="option.label"></span>
                                    <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Account Filter (Super Admin) --}}
            @if(auth()->user()->isSuperAdmin())
            <div>
                <label class="block text-[10px] font-extrabold text-on-surface-variant uppercase tracking-widest mb-1.5 px-1 opacity-70">Account</label>
                <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => 'Semua Akun']])->concat($accounts->map(fn($account) => ['value' => (string) $account->id, 'label' => $account->name])->values())), @js((string) request('account', '')))"
                     @click.outside="close()"
                     @keydown.escape.prevent.stop="close()"
                     class="relative">
                    <input type="hidden" name="account" :value="selected">
                    <button type="button"
                            @click="toggle()"
                            class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-2.5 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                            :class="open ? 'ring-2 ring-primary/20' : ''"
                            :aria-expanded="open.toString()"
                            aria-haspopup="listbox">
                        <span class="block truncate font-semibold text-on-surface-variant"
                              x-text="selectedLabel('Semua Akun')"></span>
                    </button>
                    <x-icon name="expand_more"
                            class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform"
                            x-bind:class="open ? 'rotate-180' : ''" />
                    <div x-show="open"
                         x-cloak
                         x-transition:enter="transition ease-out duration-150"
                         x-transition:enter-start="opacity-0 -translate-y-1"
                         x-transition:enter-end="opacity-100 translate-y-0"
                        class="app-select-panel absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
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
                                        class="flex w-full items-center justify-between gap-3 rounded-xl px-4 py-3 text-left text-sm transition hover:bg-primary/5 hover:text-primary">
                                    <span class="truncate font-semibold" x-text="option.label"></span>
                                    <x-icon name="check" class="h-4 w-4 text-primary" x-show="selected === option.value"></x-icon>
                                </button>
                            </template>
                        </div>
                    </div>
                </div>
            </div>
            @endif
        </div>

        <div class="flex flex-col sm:flex-row sm:items-center sm:justify-end gap-2.5 pt-1">
            @if(request()->hasAny(['search', 'status', 'account', 'start_date', 'end_date', 'month', 'year']))
            <a href="{{ route('consultations.index') }}" class="inline-flex items-center justify-center gap-1.5 text-error text-xs font-bold bg-error/5 hover:bg-error/10 px-4 py-2.5 rounded-xl transition-all h-[40px] border border-error/10 sm:w-auto">
                <x-icon name="restart_alt" class="w-4 h-4" />
                <span>Reset</span>
            </a>
            @endif
            <button type="submit" class="bg-primary text-on-primary px-5 py-2.5 rounded-xl text-sm font-bold shadow-lg shadow-primary/20 hover:bg-primary-dim transition-all active:scale-[0.98] inline-flex items-center justify-center gap-2 h-[40px] w-full sm:w-auto">
                <x-icon name="filter_alt" class="w-4 h-4" />
                <span>Terapkan Filter</span>
            </button>
        </div>
    </form>
    </div>
</div>

{{-- Data Table --}}
<div class="bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden flex flex-col">
    <div class="table-scroll-mobile overflow-x-auto scrollbar-thin scrollbar-thumb-surface-container shadow-inner">
        <table class="w-full min-w-[750px] text-left border-collapse whitespace-nowrap">
            <thead>
                <tr class="bg-surface-container-low/50">
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">ID Consultation</th>
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Nama Klien</th>
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Telepon</th>
                    @if(auth()->user()->isSuperAdmin())
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Akun</th>
                    @endif
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Nama Produk</th>
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Status</th>
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Tgl Konsul</th>
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none">Tgl Update</th>
                    <th class="px-6 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest leading-none text-right">Aksi</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-surface-container-low">
                @forelse($consultations as $c)
                <tr class="hover:bg-surface-container-low/30 transition-colors">
                    <td class="px-6 py-4">
                        <span class="text-xs font-mono font-bold text-primary bg-primary-container/30 px-2 py-1 rounded-lg">{{ $c->consultation_id }}</span>
                    </td>
                    <td class="px-6 py-4">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-surface-container flex items-center justify-center font-bold text-xs text-on-surface-variant shrink-0">
                                {{ strtoupper(substr($c->client_name, 0, 2)) }}
                            </div>
                            <div class="min-w-0">
                                <a href="{{ route('consultations.show', $c) }}" class="font-bold text-on-surface hover:text-primary transition-colors text-sm truncate block">{{ $c->client_name }}</a>
                                <p class="text-[10px] text-on-surface-variant mt-0.5">{{ $c->city }}, {{ $c->province }}</p>
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-sm text-on-surface font-medium">{{ $c->phone }}</td>
                    @if(auth()->user()->isSuperAdmin())
                    <td class="px-6 py-4">
                        <span class="text-xs font-bold text-on-surface-variant">{{ $c->account?->name ?? 'Pusat' }}</span>
                    </td>
                    @endif
<td class="px-6 py-4">
                        <div class="space-y-1 min-w-[180px] group relative">
                            <span class="text-sm font-medium text-on-surface-variant block truncate max-w-[200px]" title="{{ $c->product_names_label ?: 'Belum Ada' }}">{{ $c->product_names_label ?: 'Belum Ada' }}</span>
                            @if($c->product_details)
                            <p class="text-[11px] leading-relaxed text-outline-variant line-clamp-2 cursor-help" title="{{ $c->product_details }}">{{ $c->product_details }}</p>
                            @endif
                            <div class="absolute z-50 hidden group-hover:block left-0 top-full mt-1 w-[420px] max-w-[calc(100vw-4rem)] whitespace-normal break-words overflow-hidden bg-surface-container-high p-3 rounded-lg shadow-xl border border-surface-container text-xs">
                                <p class="font-bold text-on-surface mb-1">Nama Produk:</p>
                                <p class="text-on-surface-variant mb-2 whitespace-normal break-words leading-relaxed">{{ $c->product_names_label ?: '-' }}</p>
                                @if($c->product_details)
                                <p class="font-bold text-on-surface mb-1">Detail Kebutuhan:</p>
                                <p class="text-on-surface-variant whitespace-pre-wrap break-words leading-relaxed">{{ $c->product_details }}</p>
                                @endif
                            </div>
                        </div>
                    </td>
                    <td class="px-6 py-4">
                        <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"
                              style="{{ $c->statusCategory?->chip_style ?? 'background-color: rgba(115, 124, 127, 0.14); color: #737C7F;' }}">
                            {{ $c->statusCategory?->name ?? '-' }}
                        </span>
                    </td>
                    <td class="px-6 py-4 text-sm text-on-surface-variant font-medium">{{ $c->consultation_date?->format('d/m/Y') }}</td>
                    <td class="px-6 py-4">
                        <div class="flex flex-col text-sm font-medium text-on-surface-variant">
                            <span>{{ $c->updated_at?->format('d/m/Y') }}</span>
                            <span class="text-[10px] opacity-70">{{ $c->updated_at?->format('H:i') }}</span>
                        </div>
                    </td>
                    <td class="px-6 py-4 text-right">
                        <div class="flex justify-end gap-1">
                            <a href="{{ route('consultations.show', $c) }}"
                               class="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-primary transition-colors"
                               title="Detail">
                                <x-icon name="visibility" class="w-[18px] h-[18px]" />
                            </a>
                            <button type="button" class="btn-edit w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-primary transition-colors"
                                data-id="{{ $c->id }}"
                                data-name="{{ $c->client_name }}"
                                data-phone="{{ $c->phone }}"
                                data-province="{{ $c->province ?? '' }}"
                                data-city="{{ $c->city ?? '' }}"
                                data-district="{{ $c->district ?? '' }}"
                                data-address="{{ $c->address ?? '' }}"
                                data-account="{{ $c->account_id }}"
                                data-category="{{ $c->needs_category_id }}"
                                data-category-ids='@json($c->productCategories()->pluck("id")->map(fn ($id) => (string) $id)->values())'
                                data-product-details="{{ $c->product_details ?? '' }}"
                                data-status="{{ $c->status_category_id }}"
                                data-date="{{ $c->consultation_date?->format('Y-m-d') }}"
                                data-notes="{{ $c->notes ?? '' }}"
                                data-consultation-id="{{ $c->consultation_id }}"
                                title="Edit">
                                <x-icon name="edit" class="w-[18px] h-[18px]" />
                            </button>
                            <form method="POST" action="{{ route('consultations.destroy', $c) }}"
                                  id="delete-form-{{ $c->id }}">
                                @csrf @method('DELETE')
                                <button type="button" onclick="confirmDelete('delete-form-{{ $c->id }}', '{{ addslashes($c->client_name) }}')"
                                        class="w-8 h-8 rounded-lg hover:bg-error/10 flex items-center justify-center text-on-surface-variant hover:text-error transition-all"
                                        title="Hapus">
                                    <x-icon name="delete" class="w-[18px] h-[18px]" />
                                </button>
                            </form>
                        </div>
                    </td>
                </tr>
                @empty
                <tr>
                    <td colspan="{{ auth()->user()->isSuperAdmin() ? 9 : 8 }}" class="px-6 py-16 text-center">
                        <div class="flex flex-col items-center">
                            <x-icon name="person_off" class="w-16 h-16 text-outline-variant/30 mb-4" />
                            <p class="text-on-surface-variant font-bold">Tidak ada data konsultasi ditemukan.</p>
                            <button type="button" @click="openCreateLead()" class="text-primary font-bold text-sm hover:underline mt-4 flex items-center gap-1 cursor-pointer">
                                <x-icon name="add" class="w-4 h-4" />
                                <span>Buat Lead Baru</span>
                            </button>
                        </div>
                    </td>
                </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{-- Pagination --}}
    @if($consultations->hasPages())
    <div class="px-6 py-4 border-t border-surface-container-low/50 bg-surface-container-low/45">
        {{ $consultations->links() }}
    </div>
    @endif
</div>

@endsection



