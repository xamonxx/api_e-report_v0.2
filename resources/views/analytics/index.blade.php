@extends('layouts.app')
@section('title', 'Analisis')

@section('content')
{{-- Page Header --}}
<div class="page-header mb-6">
    <div class="page-header__content">
        <h2 class="text-3xl font-extrabold text-on-surface tracking-tight font-headline">Analisis</h2>
        <p class="text-on-surface-variant mt-1">Analisis performa konsultasi dan konversi secara visual.</p>
    </div>
</div>

{{-- Filters --}}
<div class="filter-card mb-6">
    <form method="GET"
          action="{{ route('analytics') }}"
          class="flex flex-col gap-4"
          x-data="{ periodType: @js($selectedPeriodType) }"
          @analytics-period-type.window="periodType = $event.detail">
        <div class="filter-grid">
        @if(auth()->user()->isSuperAdmin())
        <div>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Akun</label>
            <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => 'Semua Akun']])->concat($accounts->map(fn($account) => ['value' => (string) $account->id, 'label' => $account->name])->values())), @js((string) ($selectedAccount ?? '')))"
                 @click.outside="close()"
                 @keydown.escape.prevent.stop="close()"
                 class="relative">
                <input type="hidden" name="account" :value="selected">
                <button type="button"
                        @click="toggle()"
                        class="w-full bg-surface-container-high rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                        :class="open ? 'ring-2 ring-primary/20' : ''"
                        :aria-expanded="open.toString()"
                        aria-haspopup="listbox">
                    <span class="block truncate"
                          :class="selected ? 'font-semibold text-on-surface' : 'font-semibold text-on-surface'"
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

        <div>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Tipe Periode</label>
            <div x-data="searchableSelect(@js($periodTypes->map(fn($type) => ['value' => (string) $type['value'], 'label' => $type['label']])->values()), @js((string) $selectedPeriodType), 'syncAnalyticsPeriodType')"
                 @click.outside="close()"
                 @keydown.escape.prevent.stop="close()"
                 class="relative">
                <input type="hidden" name="period_type" :value="selected">
                <button type="button"
                        @click="toggle()"
                        class="w-full bg-surface-container-high rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                        :class="open ? 'ring-2 ring-primary/20' : ''"
                        :aria-expanded="open.toString()"
                        aria-haspopup="listbox">
                    <span class="block truncate font-semibold text-on-surface"
                          x-text="selectedLabel('Pilih periode...')"></span>
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
                               placeholder="Cari tipe periode..." autocomplete="off">
                    </div>
                    <div class="max-h-60 overflow-y-auto p-1.5">
                        <template x-if="filteredOptions().length === 0">
                            <div class="px-4 py-3 text-sm text-outline-variant">Tipe periode tidak ditemukan.</div>
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

        <div x-show="periodType === 'weekly'" x-cloak>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Tanggal Acuan Minggu</label>
            <input type="text"
                   id="week_date"
                   name="week_date"
                   data-datepicker
                   data-datepicker-placeholder="Pilih tanggal acuan..."
                   value="{{ $selectedWeekDate }}"
                   class="w-full bg-surface-container-high rounded-xl px-4 py-3 text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20" />
        </div>

        <div x-show="periodType !== 'weekly'" x-cloak>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Tahun</label>
            <div x-data="searchableSelect(@js(collect($years)->map(fn($year) => ['value' => (string) $year, 'label' => (string) $year])->values()), @js((string) $selectedYear))"
                 @click.outside="close()"
                 @keydown.escape.prevent.stop="close()"
                 class="relative">
                <input type="hidden" name="year" :value="selected">
                <button type="button"
                        @click="toggle()"
                        class="w-full bg-surface-container-high rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                        :class="open ? 'ring-2 ring-primary/20' : ''"
                        :aria-expanded="open.toString()"
                        aria-haspopup="listbox">
                    <span class="block truncate font-semibold text-on-surface"
                          x-text="selectedLabel('Pilih Tahun...')"></span>
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
                               placeholder="Cari tahun..." autocomplete="off">
                    </div>
                    <div class="max-h-60 overflow-y-auto p-1.5">
                        <template x-if="filteredOptions().length === 0">
                            <div class="px-4 py-3 text-sm text-outline-variant">Tahun tidak ditemukan.</div>
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

        <div x-show="periodType === 'monthly'" x-cloak>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2">Bulan</label>
            <div x-data="searchableSelect(@js(collect($months)->map(fn($month) => ['value' => (string) $month['value'], 'label' => $month['label']])->values()), @js((string) $selectedMonth))"
                 @click.outside="close()"
                 @keydown.escape.prevent.stop="close()"
                 class="relative">
                <input type="hidden" name="month" :value="selected">
                <button type="button"
                        @click="toggle()"
                        class="w-full bg-surface-container-high rounded-xl pl-4 pr-12 py-3 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                        :class="open ? 'ring-2 ring-primary/20' : ''"
                        :aria-expanded="open.toString()"
                        aria-haspopup="listbox">
                    <span class="block truncate font-semibold text-on-surface"
                          x-text="selectedLabel('Pilih Bulan...')"></span>
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
                               placeholder="Cari bulan..." autocomplete="off">
                    </div>
                    <div class="max-h-60 overflow-y-auto p-1.5">
                        <template x-if="filteredOptions().length === 0">
                            <div class="px-4 py-3 text-sm text-outline-variant">Bulan tidak ditemukan.</div>
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

        <div class="rounded-2xl border border-primary/10 bg-primary-fixed/30 px-5 py-4">
            <div class="flex flex-col gap-2 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <div class="text-[11px] font-bold uppercase tracking-[0.2em] text-primary">Periode Aktif</div>
                    <div class="mt-1 text-lg font-extrabold text-on-surface font-headline">{{ $periodLabel }}</div>
                    <div class="text-xs text-on-surface-variant">Dibandingkan dengan {{ $comparisonLabel }}</div>
                </div>
                <div class="flex flex-wrap gap-2 no-print">
                    <a href="{{ route('export.analytics.excel', $exportQuery) }}"
                       class="inline-flex items-center justify-center gap-2 rounded-xl border border-primary/20 bg-white px-4 py-2.5 text-sm font-bold text-primary hover:bg-primary/5 transition-colors">
                        <x-icon name="grid_on" class="w-4 h-4" />
                        <span>Export Excel</span>
                    </a>
                    <a href="{{ route('export.analytics.pdf', $exportQuery) }}"
                       class="inline-flex items-center justify-center gap-2 rounded-xl bg-primary px-4 py-2.5 text-sm font-bold text-on-primary shadow-lg shadow-primary/20 hover:bg-primary-dim transition-colors">
                        <x-icon name="picture_as_pdf" class="w-4 h-4" />
                        <span>Export PDF</span>
                    </a>
                </div>
            </div>
        </div>

        <div class="filter-actions">
            <button type="submit" class="w-full sm:w-auto bg-primary/10 text-primary px-5 py-2.5 rounded-xl text-sm font-semibold hover:bg-primary/20 transition-all active:scale-[0.98]">
                Filter
            </button>
            @if(request()->hasAny(['account', 'period_type', 'week_date', 'month', 'year']))
            <a href="{{ route('analytics') }}" class="w-full sm:w-auto text-center text-on-surface-variant text-xs sm:text-sm font-bold px-2 py-2 hover:text-error transition-colors">Atur Ulang</a>
            @endif
        </div>
    </form>
</div>

{{-- Summary Cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-4 gap-6 stagger-children">
    {{-- Total Konsultasi --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-primary-container/30 rounded-lg group-hover:bg-primary group-hover:text-on-primary transition-colors">
                <x-icon name="groups" class="w-5 h-5" />
            </div>
        </div>
        <h3 class="text-on-surface-variant text-xs font-medium uppercase tracking-wider mb-1">Total Konsultasi</h3>
        <p class="text-3xl font-extrabold font-headline text-on-surface">{{ number_format($totalLeads) }}</p>
    </div>

    {{-- Rasio Konversi --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-tertiary-container/30 rounded-lg group-hover:bg-tertiary group-hover:text-on-primary transition-colors">
                <x-icon name="trending_up" class="w-5 h-5" />
            </div>
        </div>
        <h3 class="text-on-surface-variant text-xs font-medium uppercase tracking-wider mb-1">Rasio Konversi</h3>
        <p class="text-3xl font-extrabold font-headline text-on-surface">{{ $conversionRate }}%</p>
    </div>

    {{-- Total Survey --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-inverse-primary/20 rounded-lg group-hover:bg-inverse-primary group-hover:text-on-primary transition-colors">
                <x-icon name="assignment" class="w-5 h-5" />
            </div>
        </div>
        <h3 class="text-on-surface-variant text-xs font-medium uppercase tracking-wider mb-1">Total Survey</h3>
        <p class="text-3xl font-extrabold font-headline text-on-surface">{{ number_format($totalSurveys) }}</p>
    </div>

    {{-- Growth --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-secondary-container/30 rounded-lg group-hover:bg-secondary group-hover:text-on-primary transition-colors">
                <x-icon name="leaderboard" class="w-5 h-5" />
            </div>
        </div>
        <h3 class="text-on-surface-variant text-xs font-medium uppercase tracking-wider mb-1">Growth</h3>
        <p class="text-3xl font-extrabold font-headline text-on-surface">{{ $growthPercent }}%</p>
        <p class="mt-2 text-[11px] font-semibold text-on-surface-variant">vs {{ $comparisonLabel }}</p>
    </div>
</div>

@php
    $pendingConfirmationCards = [
        [
            'eyebrow' => 'Provinsi',
            'title' => '% Belum Konfirmasi',
            'description' => 'Lead yang provinsinya masih memakai label "' . \App\Support\PendingConfirmation::LABEL . '".',
            'count_label' => 'Provinsi Belum Confirm',
            'icon' => 'flag',
            'border_class' => 'border-amber-500/15',
            'eyebrow_class' => 'text-amber-700',
            'icon_wrap_class' => 'bg-amber-500/10',
            'icon_class' => 'text-amber-700',
            'count_class' => 'text-amber-700',
            'bar_class' => 'bg-amber-500',
            'data' => $pendingConfirmationStats['province'] ?? ['count' => 0, 'percentage' => 0],
        ],
        [
            'eyebrow' => 'Kota / Kabupaten',
            'title' => '% Belum Konfirmasi',
            'description' => 'Lead yang kota/kabupatennya masih memakai label "' . \App\Support\PendingConfirmation::LABEL . '".',
            'count_label' => 'Kota Belum Confirm',
            'icon' => 'domain',
            'border_class' => 'border-sky-500/15',
            'eyebrow_class' => 'text-sky-700',
            'icon_wrap_class' => 'bg-sky-500/10',
            'icon_class' => 'text-sky-700',
            'count_class' => 'text-sky-700',
            'bar_class' => 'bg-sky-500',
            'data' => $pendingConfirmationStats['city'] ?? ['count' => 0, 'percentage' => 0],
        ],
        [
            'eyebrow' => 'Kecamatan',
            'title' => '% Belum Konfirmasi',
            'description' => 'Lead yang kecamatannya masih memakai label "' . \App\Support\PendingConfirmation::LABEL . '".',
            'count_label' => 'Kecamatan Belum Confirm',
            'icon' => 'location_on',
            'border_class' => 'border-violet-500/15',
            'eyebrow_class' => 'text-violet-700',
            'icon_wrap_class' => 'bg-violet-500/10',
            'icon_class' => 'text-violet-700',
            'count_class' => 'text-violet-700',
            'bar_class' => 'bg-violet-500',
            'data' => $pendingConfirmationStats['district'] ?? ['count' => 0, 'percentage' => 0],
        ],
        [
            'eyebrow' => 'Kebutuhan Produk',
            'title' => '% Belum Konfirmasi',
            'description' => 'Lead yang produk kebutuhannya masih memakai label "' . \App\Support\PendingConfirmation::LABEL . '".',
            'count_label' => 'Produk Belum Confirm',
            'icon' => 'assignment',
            'border_class' => 'border-rose-500/15',
            'eyebrow_class' => 'text-rose-700',
            'icon_wrap_class' => 'bg-rose-500/10',
            'icon_class' => 'text-rose-700',
            'count_class' => 'text-rose-700',
            'bar_class' => 'bg-rose-500',
            'data' => $pendingConfirmationStats['product'] ?? ['count' => 0, 'percentage' => 0],
        ],
    ];
@endphp

<div class="mt-6 grid grid-cols-1 md:grid-cols-2 xl:grid-cols-4 gap-6">
    @foreach($pendingConfirmationCards as $card)
    <div class="bg-surface-container-lowest p-6 sm:p-7 rounded-2xl shadow-sm border {{ $card['border_class'] }} animate-fade-in">
        <div class="flex items-start justify-between gap-4">
            <div>
                <div class="text-[10px] font-bold uppercase tracking-[0.18em] {{ $card['eyebrow_class'] }}">{{ $card['eyebrow'] }}</div>
                <h3 class="mt-2 text-xl font-bold font-headline text-on-surface">{{ $card['title'] }}</h3>
                <p class="mt-1 text-xs text-on-surface-variant">{{ $card['description'] }}</p>
            </div>
            <div class="rounded-2xl p-3 {{ $card['icon_wrap_class'] }} {{ $card['icon_class'] }}">
                <x-icon name="{{ $card['icon'] }}" class="w-6 h-6" />
            </div>
        </div>
        <div class="mt-6 flex items-end justify-between gap-4">
            <div>
                <div class="text-4xl font-extrabold font-headline text-on-surface">{{ number_format((float) ($card['data']['percentage'] ?? 0), 1) }}%</div>
                <div class="mt-2 text-sm font-semibold text-on-surface">{{ number_format($card['data']['count'] ?? 0) }} dari {{ number_format($totalLeads) }} lead</div>
            </div>
            <div class="rounded-xl bg-surface-container-low px-3 py-2 text-right">
                <div class="text-[10px] font-bold uppercase tracking-widest text-on-surface-variant">{{ $card['count_label'] }}</div>
                <div class="mt-1 text-lg font-extrabold {{ $card['count_class'] }}">{{ number_format($card['data']['count'] ?? 0) }}</div>
            </div>
        </div>
        <div class="mt-5 h-2.5 w-full overflow-hidden rounded-full bg-surface-container-high">
            <div class="h-full rounded-full transition-all duration-700 {{ $card['bar_class'] }}" style="width: {{ min((float) ($card['data']['percentage'] ?? 0), 100) }}%;"></div>
        </div>
    </div>
    @endforeach
</div>

@if($insights->isNotEmpty())
<div class="mt-8 rounded-2xl border border-surface-container-low bg-surface-container-lowest p-6 sm:p-8 shadow-sm">
    <div class="mb-4">
        <h2 class="text-xl font-bold font-headline text-on-surface">Insight Otomatis</h2>
        <p class="text-xs text-on-surface-variant">Ringkasan cepat dari pola utama pada periode {{ $periodLabel }}</p>
    </div>
    <div class="grid grid-cols-1 lg:grid-cols-2 gap-3 pb-8">
        @foreach($insights as $insight)
        <div class="flex items-start gap-3 rounded-xl bg-surface-container-low/40 px-4 py-3 text-sm font-medium text-on-surface">
            @if(isset($insight['icon']))
                <div class="mt-0.5 shrink-0 text-primary">
                    <x-icon name="{{ $insight['icon'] }}" class="w-5 h-5" />
                </div>
            @endif
            <div class="leading-relaxed [&>mark]:bg-primary/10 [&>mark]:text-primary [&>mark]:rounded [&>mark]:px-1 [&>mark]:font-bold">
                {!! isset($insight['html']) ? $insight['html'] : $insight !!}
            </div>
        </div>
        @endforeach
    </div>
</div>
@endif

{{-- Charts Row --}}
<div class="grid grid-cols-1 lg:grid-cols-5 gap-8">
    {{-- Grafik Batang: Distribusi Status --}}
    <div class="analytics-chart-card lg:col-span-3 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in border border-surface-container-low"
         style="--chart-card-delay: 40ms;">
        <div class="mb-6">
            <h2 class="text-xl font-bold font-headline text-on-surface">Distribusi Status</h2>
            <p class="text-xs text-on-surface-variant">Jumlah konsultasi per kategori status</p>
        </div>

        @php
            $maxCount = $statusDistribution->max('count') ?: 1;
        @endphp

        <div class="space-y-4 mt-6">
            @foreach($statusDistribution as $item)
            @php $barWidth = ($item['count'] / $maxCount) * 100; @endphp
            <div class="group">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-semibold text-on-surface">{{ $item['name'] }}</span>
                    <span class="text-xs font-bold text-on-surface-variant">{{ $item['count'] }}</span>
                </div>
                <div class="w-full h-3 bg-surface-container-high rounded-full overflow-hidden">
                    <div class="analytics-meter-fill h-full rounded-full transition-all duration-700 ease-out group-hover:opacity-80"
                         style="width: {{ $barWidth }}%; background-color: {{ $item['color'] }}; --chart-delay: {{ 120 + ($loop->index * 85) }}ms;"></div>
                </div>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Grafik Donat --}}
    <div class="analytics-chart-card lg:col-span-2 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm flex flex-col items-center animate-fade-in border border-surface-container-low"
         style="--chart-card-delay: 140ms;">
        <div class="w-full mb-8">
            <h2 class="text-xl font-bold font-headline text-on-surface">Komposisi Status</h2>
            <p class="text-xs text-on-surface-variant">Proporsi setiap status terhadap total</p>
        </div>

        @php
            $total = collect($statusDistribution)->sum('count');
            $cumulative = 0;
        @endphp

        <div class="relative w-48 h-48 mb-8" style="--chart-delay: 180ms;">
            <svg class="analytics-donut-chart w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                @if($total > 0)
                    @foreach($statusDistribution as $item)
                        @php
                            $pct = ($item['count'] / $total) * 100;
                            $offset = -$cumulative;
                            $cumulative += $pct;
                        @endphp
                        <circle cx="18" cy="18" r="15.915" fill="transparent"
                                stroke="{{ $item['color'] }}"
                                stroke-width="4"
                                stroke-dasharray="{{ $pct }} {{ 100 - $pct }}"
                                stroke-dashoffset="{{ $offset }}"></circle>
                    @endforeach
                @else
                    <circle cx="18" cy="18" r="15.915" fill="transparent"
                            stroke="#dbe4e7" stroke-width="4"
                            stroke-dasharray="100 0" stroke-dashoffset="0"></circle>
                @endif
            </svg>
            <div class="analytics-donut-center absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-extrabold text-on-surface">{{ number_format($total) }}</span>
                <span class="text-[9px] text-on-surface-variant font-bold uppercase">Total Konsultasi</span>
            </div>
        </div>

        <div class="w-full space-y-3">
            @foreach($statusDistribution as $item)
            <div class="analytics-legend-item flex items-center justify-between"
                 style="--chart-delay: {{ 300 + ($loop->index * 70) }}ms;">
                <div class="flex items-center gap-2">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $item['color'] }}"></span>
                    <span class="text-xs font-medium text-on-surface">{{ $item['name'] }}</span>
                </div>
                <div class="text-right">
                    <span class="text-xs font-bold text-on-surface">{{ $item['count'] }}</span>
                    <span class="text-[10px] text-on-surface-variant ml-1">({{ $total > 0 ? round(($item['count'] / $total) * 100, 1) : 0 }}%)</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Needs Category Chart Row --}}
<div class="analytics-chart-card mt-8 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in border border-surface-container-low"
     style="--chart-card-delay: 220ms;">
    <div class="mb-6">
        <h2 class="text-xl font-bold font-headline text-on-surface">Kategori Kebutuhan</h2>
        <p class="text-xs text-on-surface-variant">Distribusi konsultasi berdasarkan kategori minat pelayanan</p>
    </div>

    @php
        $maxNeedsCount = $needsDistribution->max('count') ?: 1;
    @endphp

    @if($needsDistribution->isEmpty())
        <div class="flex flex-col items-center justify-center py-10 text-on-surface-variant opacity-60">
            <x-icon name="pie_chart" class="w-10 h-10 mb-2" />
            <p class="text-[13px] font-medium">Belum ada data kategori kebutuhan pada periode ini.</p>
        </div>
    @else
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-x-8 gap-y-6 mt-6">
            @foreach($needsDistribution as $item)
            @php $barWidth = ($item['count'] / $maxNeedsCount) * 100; @endphp
            <div class="group">
                <div class="flex items-center justify-between mb-1.5">
                    <span class="text-xs font-bold text-on-surface truncate pr-2" title="{{ $item['name'] }}">{{ $item['name'] }}</span>
                    <span class="text-xs font-extrabold text-on-surface-variant">{{ $item['count'] }}</span>
                </div>
                <div class="w-full h-2.5 bg-surface-container-high rounded-full overflow-hidden shadow-inner">
                    <div class="analytics-meter-fill h-full rounded-full transition-all duration-700 ease-out bg-primary group-hover:brightness-110"
                         style="width: {{ $barWidth }}%; --chart-delay: {{ 140 + ($loop->index * 70) }}ms;"></div>
                </div>
            </div>
            @endforeach
        </div>
    @endif
</div>

{{-- Super Admin Region Analytics --}}
@if(auth()->user()->isSuperAdmin())
<div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mt-8">
    <div class="analytics-chart-card bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in border border-surface-container-low"
         style="--chart-card-delay: 40ms;">
        <div class="mb-6">
            <h2 class="text-xl font-bold font-headline text-on-surface">Grafik Distribusi Provinsi</h2>
            <p class="text-xs text-on-surface-variant">Perbandingan provinsi dengan jumlah konsultasi terbanyak</p>
        </div>

        @php
            $maxProvinceCount = $provinceDistribution->max('count') ?: 1;
        @endphp

        @if($provinceDistribution->isEmpty())
            <div class="flex flex-col items-center justify-center py-10 text-on-surface-variant opacity-60">
                <x-icon name="location_on" class="w-10 h-10 mb-2" />
                <p class="text-[13px] font-medium">Belum ada data provinsi pada periode ini.</p>
            </div>
        @else
            <div class="rounded-2xl border border-surface-container-low bg-surface-container-low/20 p-4 sm:p-5">
                <div class="-mx-1 overflow-x-auto pb-2 sm:mx-0">
                    <div class="flex h-72 min-w-max items-end gap-3 px-1 sm:min-w-0 sm:gap-4 sm:px-0">
                    @foreach($provinceDistribution as $item)
                    @php
                        $height = max(($item['count'] / $maxProvinceCount) * 100, 8);
                    @endphp
                    <div class="h-full w-20 shrink-0 flex flex-col justify-end items-center gap-3 sm:w-auto sm:flex-1 sm:min-w-0">
                        <div class="text-center">
                            <div class="text-sm font-extrabold text-on-surface">{{ $item['count'] }}</div>
                            <div class="text-[10px] text-on-surface-variant">{{ $item['percentage'] }}%</div>
                        </div>
                        <div class="analytics-column-bar w-8 sm:w-full max-w-16 sm:max-w-20 rounded-t-2xl bg-primary shadow-[0_12px_30px_rgba(37,99,235,0.22)] transition-all duration-700 hover:-translate-y-1"
                             style="height: {{ $height }}%; --chart-delay: {{ 140 + ($loop->index * 90) }}ms;"></div>
                        <div class="w-full text-center">
                            <div class="text-[11px] font-bold text-on-surface break-words leading-tight sm:line-clamp-2">{{ $item['name'] }}</div>
                        </div>
                    </div>
                    @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($provinceDistribution as $index => $item)
                <div class="analytics-legend-item flex items-center justify-between gap-3 rounded-xl bg-surface-container-low/40 px-4 py-3"
                     style="--chart-delay: {{ 380 + ($loop->index * 60) }}ms;">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-7 h-7 rounded-full bg-primary/10 text-primary flex items-center justify-center text-[11px] font-extrabold shrink-0">{{ $index + 1 }}</div>
                        <span class="text-sm font-semibold text-on-surface truncate">{{ $item['name'] }}</span>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-sm font-extrabold text-on-surface">{{ $item['count'] }}</div>
                        <div class="text-[10px] text-on-surface-variant">{{ $item['percentage'] }}%</div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="analytics-chart-card bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in border border-surface-container-low"
         style="--chart-card-delay: 140ms;">
        <div class="mb-6">
            <h2 class="text-xl font-bold font-headline text-on-surface">Grafik Kota / Kabupaten</h2>
            <p class="text-xs text-on-surface-variant">Perbandingan kota atau kabupaten dengan volume konsultasi tertinggi</p>
        </div>

        @php
            $maxCityCount = $cityDistribution->max('count') ?: 1;
        @endphp

        @if($cityDistribution->isEmpty())
            <div class="flex flex-col items-center justify-center py-10 text-on-surface-variant opacity-60">
                <x-icon name="pin_drop" class="w-10 h-10 mb-2" />
                <p class="text-[13px] font-medium">Belum ada data kota pada periode ini.</p>
            </div>
        @else
            <div class="rounded-2xl border border-surface-container-low bg-surface-container-low/20 p-4 sm:p-5">
                <div class="-mx-1 overflow-x-auto pb-2 sm:mx-0">
                    <div class="flex h-72 min-w-max items-end gap-3 px-1 sm:min-w-0 sm:gap-4 sm:px-0">
                    @foreach($cityDistribution as $item)
                    @php
                        $height = max(($item['count'] / $maxCityCount) * 100, 8);
                    @endphp
                    <div class="h-full w-20 shrink-0 flex flex-col justify-end items-center gap-3 sm:w-auto sm:flex-1 sm:min-w-0">
                        <div class="text-center">
                            <div class="text-sm font-extrabold text-on-surface">{{ $item['count'] }}</div>
                            <div class="text-[10px] text-on-surface-variant">{{ $item['percentage'] }}%</div>
                        </div>
                        <div class="analytics-column-bar w-8 sm:w-full max-w-16 sm:max-w-20 rounded-t-2xl bg-tertiary shadow-[0_12px_30px_rgba(17,126,88,0.24)] transition-all duration-700 hover:-translate-y-1"
                             style="height: {{ $height }}%; --chart-delay: {{ 140 + ($loop->index * 90) }}ms;"></div>
                        <div class="w-full text-center">
                            <div class="text-[11px] font-bold text-on-surface break-words leading-tight sm:line-clamp-2">{{ $item['name'] }}</div>
                        </div>
                    </div>
                    @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($cityDistribution as $index => $item)
                <div class="analytics-legend-item flex items-center justify-between gap-3 rounded-xl bg-surface-container-low/40 px-4 py-3"
                     style="--chart-delay: {{ 380 + ($loop->index * 60) }}ms;">
                    <div class="flex items-center gap-3 min-w-0">
                        <div class="w-7 h-7 rounded-full bg-tertiary/10 text-tertiary flex items-center justify-center text-[11px] font-extrabold shrink-0">{{ $index + 1 }}</div>
                        <span class="text-sm font-semibold text-on-surface truncate">{{ $item['name'] }}</span>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-sm font-extrabold text-on-surface">{{ $item['count'] }}</div>
                        <div class="text-[10px] text-on-surface-variant">{{ $item['percentage'] }}%</div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-5 gap-8 mt-8">
    <div class="analytics-chart-card lg:col-span-3 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm animate-fade-in border border-surface-container-low"
         style="--chart-card-delay: 60ms;">
        <div class="mb-6">
            <h2 class="text-xl font-bold font-headline text-on-surface">Grafik Segmen Jawa Barat</h2>
            <p class="text-xs text-on-surface-variant">Perbandingan tiap segmen wilayah Bandung Raya, Pantura, Jabar Pantura, Kulon, dan Jabodetabek</p>
        </div>

        @php
            $maxWestJavaCount = $westJavaSegmentDistribution->max('count') ?: 1;
            $totalWestJava = $westJavaSegmentDistribution->sum('count');
        @endphp

        @if($totalWestJava === 0)
            <div class="flex flex-col items-center justify-center py-10 text-on-surface-variant opacity-60">
                <x-icon name="donut_small" class="w-10 h-10 mb-2" />
                <p class="text-[13px] font-medium">Belum ada data segmen Jawa Barat pada periode ini.</p>
            </div>
        @else
            <div class="rounded-2xl border border-surface-container-low bg-surface-container-low/20 p-4 sm:p-5">
                <div class="-mx-1 overflow-x-auto pb-2 sm:mx-0">
                    <div class="flex h-80 min-w-max items-end gap-3 px-1 sm:min-w-0 sm:gap-4 sm:px-0">
                    @foreach($westJavaSegmentDistribution as $item)
                    @php
                        $height = max(($item['count'] / $maxWestJavaCount) * 100, $item['count'] > 0 ? 8 : 2);
                    @endphp
                    <div class="h-full w-24 shrink-0 flex flex-col justify-end items-center gap-3 sm:w-auto sm:flex-1 sm:min-w-0">
                        <div class="text-center">
                            <div class="text-sm font-extrabold text-on-surface">{{ $item['count'] }}</div>
                            <div class="text-[10px] text-on-surface-variant">
                                {{ $totalWestJava > 0 ? round(($item['count'] / $totalWestJava) * 100, 1) : 0 }}%
                            </div>
                        </div>
                        <div class="analytics-column-bar w-8 sm:w-full max-w-16 sm:max-w-20 rounded-t-2xl transition-all duration-700 hover:-translate-y-1"
                             style="height: {{ $height }}%; background-color: {{ $item['color'] }}; box-shadow: 0 12px 30px rgba(15, 23, 42, 0.12); --chart-delay: {{ 140 + ($loop->index * 90) }}ms;"></div>
                        <div class="w-full text-center">
                            <div class="text-[11px] font-bold text-on-surface break-words leading-tight sm:line-clamp-2">{{ $item['name'] }}</div>
                        </div>
                    </div>
                    @endforeach
                    </div>
                </div>
            </div>

            <div class="mt-5 grid grid-cols-1 sm:grid-cols-2 gap-3">
                @foreach($westJavaSegmentDistribution as $item)
                <div class="analytics-legend-item flex items-center justify-between gap-3 rounded-xl bg-surface-container-low/40 px-4 py-3"
                     style="--chart-delay: {{ 380 + ($loop->index * 60) }}ms;">
                    <div class="flex items-center gap-3 min-w-0">
                        <span class="w-3 h-3 rounded-full shrink-0" style="background-color: {{ $item['color'] }}"></span>
                        <span class="text-sm font-semibold text-on-surface truncate">{{ $item['name'] }}</span>
                    </div>
                    <div class="text-right shrink-0">
                        <div class="text-sm font-extrabold text-on-surface">{{ $item['count'] }}</div>
                        <div class="text-[10px] text-on-surface-variant">{{ $totalWestJava > 0 ? round(($item['count'] / $totalWestJava) * 100, 1) : 0 }}%</div>
                    </div>
                </div>
                @endforeach
            </div>
        @endif
    </div>

    <div class="analytics-chart-card lg:col-span-2 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm flex flex-col items-center animate-fade-in border border-surface-container-low"
         style="--chart-card-delay: 160ms;">
        <div class="w-full mb-8">
            <h2 class="text-xl font-bold font-headline text-on-surface">Proporsi Jawa Barat</h2>
            <p class="text-xs text-on-surface-variant">Proporsi per segmen wilayah Jawa Barat</p>
        </div>

        @php
            $westJavaCumulative = 0;
        @endphp

        <div class="relative w-48 h-48 mb-8" style="--chart-delay: 180ms;">
            <svg class="analytics-donut-chart w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                @if($totalWestJava > 0)
                    @foreach($westJavaSegmentDistribution as $item)
                        @php
                            $pct = ($item['count'] / $totalWestJava) * 100;
                            $offset = -$westJavaCumulative;
                            $westJavaCumulative += $pct;
                        @endphp
                        <circle cx="18" cy="18" r="15.915" fill="transparent"
                                stroke="{{ $item['color'] }}"
                                stroke-width="4"
                                stroke-dasharray="{{ $pct }} {{ 100 - $pct }}"
                                stroke-dashoffset="{{ $offset }}"></circle>
                    @endforeach
                @else
                    <circle cx="18" cy="18" r="15.915" fill="transparent"
                            stroke="#dbe4e7" stroke-width="4"
                            stroke-dasharray="100 0" stroke-dashoffset="0"></circle>
                @endif
            </svg>
            <div class="analytics-donut-center absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-extrabold text-on-surface">{{ number_format($totalWestJava) }}</span>
                <span class="text-[9px] text-on-surface-variant font-bold uppercase">Total Jabar</span>
            </div>
        </div>

        <div class="w-full space-y-3">
            @foreach($westJavaSegmentDistribution as $item)
            <div class="analytics-legend-item flex items-center justify-between gap-3"
                 style="--chart-delay: {{ 300 + ($loop->index * 70) }}ms;">
                <div class="flex items-center gap-2 min-w-0">
                    <span class="w-2.5 h-2.5 rounded-full shrink-0" style="background-color: {{ $item['color'] }}"></span>
                    <span class="text-xs font-medium text-on-surface truncate">{{ $item['name'] }}</span>
                </div>
                <div class="text-right shrink-0">
                    <span class="text-xs font-bold text-on-surface">{{ $item['count'] }}</span>
                    <span class="text-[10px] text-on-surface-variant ml-1">({{ $totalWestJava > 0 ? round(($item['count'] / $totalWestJava) * 100, 1) : 0 }}%)</span>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>
@endif

{{-- Super Admin Rankings --}}
@if(auth()->user()->isSuperAdmin())
<div class="grid grid-cols-1 xl:grid-cols-2 gap-8 mt-8">

    {{-- Peringkat Akun --}}
    @if($accountRanking->count())
    <div class="table-panel animate-fade-in flex flex-col">
        <div class="px-6 sm:px-8 py-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h2 class="text-xl font-bold font-headline text-on-surface">Peringkat Akun</h2>
                <p class="text-xs text-on-surface-variant">Peringkat berdasarkan rasio survey</p>
            </div>
        </div>
        <div class="table-scroll-mobile overflow-x-auto flex-1">
            <table class="w-full min-w-max text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-surface-container-low/50">
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Peringkat</th>
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Akun</th>
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Total Konsultasi</th>
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Total Survey</th>
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Rasio Konversi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-low">
                    @foreach($accountRanking as $index => $item)
                    <tr class="hover:bg-surface-container-low/30 transition-colors">
                        <td class="px-8 py-5">
                            @php
                                $badgeColors = ['bg-amber-100 text-amber-700', 'bg-slate-200 text-slate-700', 'bg-orange-100 text-orange-700'];
                                $badgeClass = $badgeColors[$index] ?? 'bg-surface-container text-on-surface-variant';
                            @endphp
                            <div class="w-8 h-8 rounded-full {{ $badgeClass }} flex items-center justify-center font-bold text-sm">{{ $index + 1 }}</div>
                        </td>
                        <td class="px-8 py-5">
                            <span class="font-semibold text-on-surface">{{ $item['name'] }}</span>
                        </td>
                        <td class="px-8 py-5">
                            <span class="font-bold text-on-surface">{{ $item['total'] }}</span>
                        </td>
                        <td class="px-8 py-5">
                            <span class="text-sm font-bold text-tertiary">{{ $item['surveys'] }}</span>
                        </td>
                        <td class="px-8 py-5">
                            <div class="flex items-center gap-3">
                                <div class="w-24 h-1.5 bg-surface-container-high rounded-full overflow-hidden">
                                    <div class="h-full bg-primary rounded-full" style="width: {{ min($item['rate'], 100) }}%"></div>
                                </div>
                                <span class="text-sm font-bold {{ $item['rate'] > 20 ? 'text-tertiary' : 'text-on-surface-variant' }}">
                                    {{ $item['rate'] }}%
                                </span>
                            </div>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

    {{-- Peringkat Admin --}}
    @if(isset($adminRanking) && $adminRanking->count())
    <div class="table-panel animate-fade-in flex flex-col">
        <div class="px-6 sm:px-8 py-6 flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div>
                <h2 class="text-xl font-bold font-headline text-on-surface">Peringkat Admin</h2>
                <p class="text-xs text-on-surface-variant">Peringkat berdasarkan volume klien yang masuk</p>
            </div>
        </div>
        <div class="table-scroll-mobile overflow-x-auto flex-1">
            <table class="w-full min-w-max text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-surface-container-low/50">
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest w-16">Peringkat</th>
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Nama Admin</th>
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Akun</th>
                        <th class="px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-right">Total Klien Input</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-low">
                    @foreach($adminRanking as $index => $item)
                    <tr class="hover:bg-surface-container-low/30 transition-colors">
                        <td class="px-8 py-5">
                            @php
                                $badgeColors = ['bg-amber-100 text-amber-700', 'bg-slate-200 text-slate-700', 'bg-orange-100 text-orange-700'];
                                $badgeClass = $badgeColors[$index] ?? 'bg-surface-container text-on-surface-variant';
                            @endphp
                            <div class="w-8 h-8 rounded-full {{ $badgeClass }} flex items-center justify-center font-bold text-sm">{{ $index + 1 }}</div>
                        </td>
                        <td class="px-8 py-5">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-secondary-container text-secondary-dim flex items-center justify-center font-bold text-xs shrink-0">
                                    {{ strtoupper(substr($item['name'], 0, 2)) }}
                                </div>
                                <span class="font-semibold text-on-surface">{{ $item['name'] }}</span>
                            </div>
                        </td>
                        <td class="px-8 py-5">
                            <span class="text-on-surface-variant text-sm">{{ $item['account'] }}</span>
                        </td>
                        <td class="px-8 py-5 text-right">
                            <span class="text-lg font-bold text-primary">{{ $item['total'] }}</span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
    @endif

</div>
@endif
@endsection
