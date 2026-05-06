@extends('layouts.app')
@section('title', 'Edit Konsultasi')

@section('content')
<div class="max-w-3xl mx-auto px-1 sm:px-0">
    {{-- Header --}}
    <div class="flex items-center gap-4 mb-8">
        <a href="{{ route('consultations.index') }}" class="w-10 h-10 rounded-xl bg-surface-container flex items-center justify-center text-on-surface-variant hover:bg-surface-container-high transition-all active:scale-90 shrink-0">
            <x-icon name="arrow_back" class="w-5 h-5" />
        </a>
        <div class="min-w-0">
            <h2 class="text-2xl font-extrabold text-on-surface font-headline truncate">Update Data Lead</h2>
            <p class="text-[10px] sm:text-xs text-on-surface-variant truncate font-bold uppercase tracking-widest mt-1 opacity-70">
                ID: {{ $consultation->consultation_id }} • Aktual {{ $consultation->updated_at->diffForHumans() }}
            </p>
        </div>
    </div>

    {{-- Form Card --}}
    <div class="bg-surface-container-lowest rounded-2xl shadow-sm p-5 sm:p-8 border border-surface-container-low">
        @if($errors->any())
        <div class="bg-error/10 text-error px-4 py-3 rounded-xl text-sm font-medium mb-6">
            <div class="flex items-center gap-2 mb-2">
                <x-icon name="error" class="w-[18px] h-[18px]" />
                <span class="font-bold">Terdapat kesalahan:</span>
            </div>
            <ul class="list-disc list-inside text-xs space-y-1 opacity-90">
                @foreach($errors->all() as $error)
                <li>{{ $error }}</li>
                @endforeach
            </ul>
        </div>
        @endif

        @php
            $pendingConfirmationLabel = \App\Support\PendingConfirmation::LABEL;
            $pendingConfirmationProductId = $categories->firstWhere('name', $pendingConfirmationLabel)?->id;
            $otherNeedsLabel = \App\Models\NeedsCategory::OTHER_OPTION_LABEL;
            $otherNeedsProductId = $categories->firstWhere('name', $otherNeedsLabel)?->id;
        @endphp

        <form method="POST" action="{{ route('consultations.update', $consultation) }}" class="space-y-6 sm:space-y-8">
            @csrf @method('PUT')

            {{-- Client Name + Phone --}}
            <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                <div class="space-y-2">
                    <label for="client_name" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Nama Klien <span class="text-error">*</span></label>
                    <input type="text" id="client_name" name="client_name" value="{{ old('client_name', $consultation->client_name) }}" maxlength="100"
                           class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-bold"
                           placeholder="Nama lengkap klien" required />
                </div>
                <div class="space-y-2">
                    <label for="phone" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">No. Telepon/WA <span class="text-error">*</span></label>
                    <div x-data="phoneInputState(@js(old('phone', $consultation->phone)))" x-init="init()">
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
                        <p class="phone-input-shell__hint">Masukkan nomor lokal atau +62. Sistem otomatis menyimpan dalam format nasional WhatsApp.</p>
                    </div>
                </div>
            </div>

            {{-- Province + City (with auto-fill) --}}
            <div x-data="cityAutoFill(@js(old('city', $consultation->city ?? '')), @js(old('province', $consultation->province ?? '')), @js(old('district', $consultation->district ?? '')))" class="space-y-5 sm:space-y-6">
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
                        <label for="province" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">
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
                                    id="province"
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
                                class="app-select-panel absolute left-0 right-0 top-full z-40 mt-2 overflow-hidden rounded-2xl border border-surface-container-low bg-surface-container-lowest shadow-2xl">
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
                        <label for="city" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kota / Kabupaten</label>
                        <input type="text" id="city" name="city" value="{{ old('city', $consultation->city) }}" x-model="city" maxlength="100"
                               @input="onCityInput()" @blur="setTimeout(() => showCitySuggestions = false, 200)" @focus="onCityInput()"
                               class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-medium"
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

                {{-- Kecamatan + Alamat Lengkap --}}
                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                    <div class="space-y-2 relative">
                        <label for="district" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Kecamatan <span class="text-outline-variant font-medium normal-case">(opsional)</span></label>
                        <input type="text" id="district" name="district" value="{{ old('district', $consultation->district) }}" maxlength="100" x-model="district"
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
                        <label for="address" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Alamat Lengkap <span class="text-outline-variant font-medium normal-case">(opsional)</span></label>
                        <textarea id="address" name="address" rows="2" maxlength="500"
                                  class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant font-medium"
                                  placeholder="Masukkan alamat lengkap">{{ old('address', $consultation->address) }}</textarea>
                    </div>
                </div>
            </div>

            {{-- Account selection (Super Admin) --}}
            @if(auth()->user()->isSuperAdmin())
            <div class="space-y-2">
                <label for="account_id" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Akun <span class="text-error">*</span></label>
                <div x-data="searchableSelect(@js($accounts->map(fn($account) => ['value' => (string) $account->id, 'label' => $account->name])->values()), @js(old('account_id', $consultation->account_id)))"
                     @click.outside="close()"
                     @keydown.escape.prevent.stop="close()"
                     class="relative">
                    <input type="hidden" name="account_id" :value="selected" required>
                    <button type="button"
                            id="account_id"
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
                    <x-icon name="expand_more" class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                    <div x-show="open" x-cloak
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

            @php
                $selectedProductIds = collect(old('needs_category_ids', $consultation->productCategories()->pluck('id')->all()))
                    ->map(fn ($value) => (string) $value)
                    ->all();
                $oldProductDetails = old('product_details', $consultation->product_details);
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
                            <span class="text-[11px] text-outline-variant">Satu konsumen bisa memilih lebih dari satu produk.</span>
                        </div>
                    </div>
                    <div class="rounded-lg border border-primary/15 bg-primary/5 px-3 py-2.5">
                        <div class="flex items-center justify-between gap-3">
                            <span class="text-[10px] font-bold uppercase tracking-[0.18em] text-primary/80">Produk Terpilih</span>
                            <span class="text-[11px] text-outline-variant" x-text="selectedProducts().length ? `${selectedProducts().length} jenis dipilih` : 'Belum ada pilihan'"></span>
                        </div>
                        <div class="mt-2.5 flex flex-wrap gap-1.5" x-show="selectedProducts().length" x-cloak>
                            <template x-for="product in selectedProducts()" :key="product.id">
                                <span class="inline-flex items-center rounded-full border border-primary/15 bg-white px-2.5 py-1 text-[11px] font-semibold text-primary shadow-sm" x-text="product.name"></span>
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
                    <label for="product_details" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">
                        Detail Keterangan <span class="text-error">*</span>
                    </label>
                    <textarea id="product_details" name="product_details" rows="3" maxlength="1500"
                              x-bind:required="shouldShowOtherDetails()"
                              x-bind:disabled="!shouldShowOtherDetails()"
                              class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant leading-relaxed font-medium"
                              placeholder="Jelaskan kebutuhan produk untuk pilihan Lain-lain...">{{ $oldProductDetails }}</textarea>
                </div>

                <div class="grid grid-cols-1 md:grid-cols-2 gap-5 sm:gap-6">
                    <div class="space-y-2">
                        <label for="status_category_id" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Status Prospek <span class="text-error">*</span></label>
                        <div x-data="searchableSelect(@js($statuses->map(fn($status) => ['value' => (string) $status->id, 'label' => $status->name])->values()), @js(old('status_category_id', $consultation->status_category_id)))"
                             @click.outside="close()"
                             @keydown.escape.prevent.stop="close()"
                             class="relative">
                            <input type="hidden" name="status_category_id" :value="selected" required>
                            <button type="button"
                                    id="status_category_id"
                                    @click="toggle()"
                                    class="w-full bg-surface-container-low rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                                    :class="open ? 'ring-2 ring-primary/20' : ''"
                                    :aria-expanded="open.toString()"
                                    aria-haspopup="listbox">
                                <span class="block truncate"
                                      :class="selected ? 'font-bold text-on-surface' : 'font-bold text-outline-variant'"
                                      x-text="selectedLabel('Pilih Status...')"></span>
                            </button>
                            <button x-show="selected"
                                    x-cloak
                                    type="button"
                                    @click.stop="clear()"
                                    class="absolute right-10 top-1/2 -translate-y-1/2 rounded-md p-1 text-outline-variant transition hover:bg-surface-container hover:text-on-surface"
                                    aria-label="Kosongkan status">
                                <x-icon name="close" class="w-4 h-4" />
                            </button>
                            <x-icon name="expand_more" class="w-5 h-5 absolute right-4 top-1/2 -translate-y-1/2 text-outline-variant pointer-events-none transition-transform" x-bind:class="open ? 'rotate-180' : ''" />
                            <div x-show="open" x-cloak
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
            </div>

            <div class="grid grid-cols-1 md:grid-cols-2 gap-6">
                <div class="space-y-2">
                    <label for="consultation_date" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Tanggal Konsultasi</label>
                    <input type="date" id="consultation_date" name="consultation_date" value="{{ old('consultation_date', $consultation->consultation_date?->format('Y-m-d')) }}"
                           class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 shadow-inner font-medium" />
                </div>
            </div>

            <div class="space-y-2">
                <label for="notes" class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest px-1">Keterangan Tambahan</label>
                <textarea id="notes" name="notes" rows="4" maxlength="1000"
                          class="w-full bg-surface-container-low border-0 rounded-xl px-4 py-3 text-sm focus:ring-2 focus:ring-primary/20 resize-none shadow-inner placeholder:text-outline-variant leading-relaxed font-medium">{{ old('notes', $consultation->notes) }}</textarea>
            </div>

            {{-- Actions --}}
            <div class="flex flex-col-reverse sm:flex-row gap-4 pt-6 border-t border-surface-container-low">
                <a href="{{ route('consultations.index') }}"
                   class="flex-1 sm:flex-none flex items-center justify-center border border-outline-variant/30 text-on-surface-variant px-8 py-3.5 rounded-xl text-sm font-bold hover:bg-surface-container transition-all active:scale-95">
                    Batal
                </a>
                <button type="submit"
                        class="flex-1 sm:flex-none flex items-center justify-center bg-primary text-on-primary px-10 py-3.5 rounded-xl font-bold text-sm shadow-xl shadow-primary/20 hover:bg-primary-dim transition-all hover:scale-[1.02] active:scale-[0.98] gap-2">
                    <x-icon name="save" class="w-4 h-4" />
                    <span>Simpan Perubahan</span>
                </button>
            </div>
        </form>
    </div>
</div>
@endsection
