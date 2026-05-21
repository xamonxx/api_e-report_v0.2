@extends('layouts.app')
@section('title', $account->name)

@section('content')
{{-- React Dashboard Mount Point --}}
<div id="dashboard-root" data-role="{{ auth()->user()->role->value }}" class="hidden"></div>

{{-- Page Header --}}
<div class="flex flex-col md:flex-row md:items-end justify-between gap-4">
    <div>
        <h2 class="text-2xl sm:text-3xl font-extrabold text-on-surface tracking-tight font-headline">Dashboard Akun</h2>
        <p class="text-sm sm:text-base text-on-surface-variant mt-1">Mengelola lead dan kinerja untuk Akun {{ $account->name }}.</p>
    </div>
    <a href="{{ route('consultations.create') }}"
       class="bg-primary text-on-primary px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-primary-dim transition-all shadow-xl shadow-primary/10 no-print w-full md:w-auto">
        <x-icon name="add_circle" class="w-5 h-5" />
        <span>Tambah Konsultasi Baru</span>
    </a>
</div>

{{-- Absensi Report Banner --}}
@if(!$hasReportedToday)
<div class="relative z-20 bg-error-container/20 border border-error/20 p-4 sm:p-6 rounded-xl flex flex-col xl:flex-row justify-between items-center gap-6 animate-fade-in my-6">
    <div class="flex items-center gap-4 w-full xl:w-auto">
        <div class="w-12 h-12 rounded-full bg-error-container flex items-center justify-center text-error shrink-0">
            <x-icon name="assignment_late" class="w-5 h-5" />
        </div>
        <div>
            <h3 class="font-bold text-error text-lg leading-tight">Absensi Report Harian Belum Dilakukan!</h3>
            <p class="text-sm text-error/80 mt-1 mb-2">Silakan konfirmasi kehadiran laporan harian Anda untuk tanggal {{ \Carbon\Carbon::today()->translatedFormat('d F Y') }}.</p>
            <span class="text-[10px] font-extrabold text-error px-2.5 py-1 bg-white/60 rounded-md uppercase tracking-wider border border-error/10">Di tunggu laporannya setiap hari maksimal jam 15:00</span>
        </div>
    </div>
    <form action="{{ route('report-attendance.store') }}" method="POST" class="flex flex-col sm:flex-row items-center gap-3 w-full xl:w-auto">
        @csrf
        <div x-data="{ open: false, selected: '', selectedText: 'Pilih Jenis Laporan...' }" class="flex flex-col w-full sm:w-auto relative min-w-0 sm:min-w-[240px]">
            {{-- Input hidden to handle the actual form submission, no required attribute so it defers to Laravel backend validation natively --}}
            <input type="hidden" name="report_category" :value="selected">
            
            {{-- Custom Select Button --}}
            <button type="button" @click="open = !open" @click.away="open = false"
                    class="w-full bg-surface-container-lowest border border-error/30 text-sm focus:ring-2 focus:ring-error/20 focus:outline-none px-4 py-3 rounded-xl shadow-inner font-medium flex items-center justify-between transition-all"
                    :class="open ? 'ring-2 ring-error/20 border-error/50 text-on-surface' : (selected === '' ? 'text-on-surface-variant hover:border-error/50' : 'text-on-surface hover:border-error/50')">
                <span x-text="selectedText" class="truncate max-w-[200px] text-left"></span>
                <x-icon name="expand_more" class="w-5 h-5 text-error/60 transition-transform duration-200" ::class="open ? 'rotate-180' : ''" />
            </button>
            
            {{-- Custom Options Dropdown --}}
            <div x-show="open" x-cloak 
                 x-transition:enter="transition ease-out duration-100"
                 x-transition:enter-start="opacity-0 scale-95"
                 x-transition:enter-end="opacity-100 scale-100"
                 x-transition:leave="transition ease-in duration-75"
                 x-transition:leave-start="opacity-100 scale-100"
                 x-transition:leave-end="opacity-0 scale-95"
                 class="absolute top-full left-0 right-0 mt-2 bg-surface-container-lowest border border-error/20 rounded-xl shadow-xl z-50 overflow-hidden divide-y divide-surface-container-low py-1">
                 
                <button type="button" @click="selected = 'ada_wa'; selectedText = 'Laporan - ada WA Konsumen baru'; open = false"
                        class="w-full text-left px-4 py-3 text-sm hover:bg-error/10 hover:text-error transition-colors flex items-center justify-between"
                        :class="selected === 'ada_wa' ? 'bg-error/10 text-error font-bold' : 'text-on-surface font-medium'">
                    <span>Laporan - ada WA Konsumen baru</span>
                    <template x-if="selected === 'ada_wa'"><x-icon name="check" class="w-[18px] h-[18px]" /></template>
                </button>
                <button type="button" @click="selected = 'nol_wa'; selectedText = 'Laporan - 0 data WA Konsumen baru'; open = false"
                        class="w-full text-left px-4 py-3 text-sm hover:bg-error/10 hover:text-error transition-colors flex items-center justify-between"
                        :class="selected === 'nol_wa' ? 'bg-error/10 text-error font-bold' : 'text-on-surface font-medium'">
                    <span>Laporan - 0 data WA Konsumen baru</span>
                    <template x-if="selected === 'nol_wa'"><x-icon name="check" class="w-[18px] h-[18px]" /></template>
                </button>
                <button type="button" @click="selected = 'libur_susulan'; selectedText = 'Rekapan laporan susulan / Hari Libur'; open = false"
                        class="w-full text-left px-4 py-3 text-sm hover:bg-error/10 hover:text-error transition-colors flex items-center justify-between"
                        :class="selected === 'libur_susulan' ? 'bg-error/10 text-error font-bold' : 'text-on-surface font-medium'">
                    <span>Rekapan laporan susulan / Hari Libur</span>
                    <template x-if="selected === 'libur_susulan'"><x-icon name="check" class="w-[18px] h-[18px]" /></template>
                </button>
            </div>
            
            @error('report_category')
                <span class="absolute -bottom-6 left-0 text-[10px] font-bold text-error bg-white/80 px-2 py-1 rounded shadow-sm border border-error/10">{{ $message }}</span>
            @enderror
        </div>
        <button type="submit" class="w-full sm:w-auto mt-2 sm:mt-0 bg-error text-on-error px-6 py-3 rounded-xl font-bold flex items-center justify-center gap-2 hover:bg-error-dim transition-colors shadow-lg shadow-error/20 whitespace-nowrap self-start">
            <x-icon name="edit_document" class="w-5 h-5" />
            <span>Kirim Report</span>
        </button>
    </form>
</div>
@else
<div class="bg-tertiary-container/30 border border-tertiary/20 p-4 rounded-xl flex items-center gap-4 animate-fade-in my-6">
    <div class="w-10 h-10 rounded-full bg-tertiary-container flex items-center justify-center text-tertiary shrink-0">
        <x-icon name="task_alt" class="w-5 h-5" />
    </div>
    <div>
        <h3 class="font-bold text-tertiary">Absensi Terkonfirmasi</h3>
        <p class="text-xs text-tertiary/80">Anda telah melakukan report harian untuk tanggal {{ \Carbon\Carbon::today()->translatedFormat('d F Y') }}.</p>
    </div>
</div>
@endif

{{-- Metric Cards --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 stagger-children">
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm flex flex-col justify-between h-40 group hover-lift animate-fade-in">
        <div class="flex justify-between items-start">
            <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Total Lead</span>
            <div class="w-10 h-10 rounded-lg bg-primary-container/30 flex items-center justify-center text-primary">
                <x-icon name="groups" class="w-5 h-5" />
            </div>
        </div>
        <div>
            <p class="text-3xl sm:text-4xl font-extrabold text-on-surface font-headline">{{ number_format($totalLeads) }}</p>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm flex flex-col justify-between h-40 group hover-lift animate-fade-in">
        <div class="flex justify-between items-start">
            <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Tingkat Konversi</span>
            <div class="w-10 h-10 rounded-lg bg-tertiary-container/30 flex items-center justify-center text-tertiary">
                <x-icon name="verified" class="w-5 h-5" />
            </div>
        </div>
        <div>
            <p class="text-3xl sm:text-4xl font-extrabold text-on-surface font-headline">{{ $conversionRate }}%</p>
            <p class="text-xs text-tertiary font-medium flex items-center gap-1 mt-1">
                <x-icon name="north_east" class="w-3.5 h-3.5" />
                {{ $conversionRate > 20 ? 'Di atas' : 'Di bawah' }} rata-rata
            </p>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm flex flex-col justify-between h-40 group hover-lift animate-fade-in">
        <div class="flex justify-between items-start">
            <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Total Request Survey</span>
            <div class="w-10 h-10 rounded-lg bg-secondary-container/30 flex items-center justify-center text-secondary">
                <x-icon name="calendar_month" class="w-5 h-5" />
            </div>
        </div>
        <div>
            <p class="text-3xl sm:text-4xl font-extrabold text-on-surface font-headline">{{ $pendingSurveys }}</p>
            <p class="text-xs text-on-surface-variant font-medium mt-1">
                {{ $pendingSurveys > 0 ? 'Status Request Survey di akun ini' : 'Belum ada Request Survey di akun ini' }}
            </p>
            <p class="text-[11px] text-outline-variant mt-1 truncate" title="{{ $account->name }}">
                Akun: {{ $account->name }}
            </p>
        </div>
    </div>

    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm flex flex-col justify-between h-40 group hover-lift animate-fade-in">
        <div class="flex justify-between items-start">
            <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Progres Target</span>
            <div class="w-10 h-10 rounded-lg bg-on-primary-fixed/10 flex items-center justify-center text-on-primary-fixed">
                <x-icon name="flag" class="w-5 h-5" />
            </div>
        </div>
        <div>
            <p class="text-xl sm:text-2xl font-extrabold text-on-surface font-headline leading-tight">{{ $totalLeads }} / {{ $account->target_leads }}</p>
            <div class="w-full bg-surface-container h-1.5 rounded-full mt-3 overflow-hidden">
                <div class="bg-primary h-full rounded-full" style="width: {{ min(($totalLeads / max($account->target_leads, 1)) * 100, 100) }}%"></div>
            </div>
        </div>
    </div>
</div>

{{-- Chart + Table Row --}}
<div class="grid grid-cols-1 lg:grid-cols-12 gap-6 sm:gap-8 items-start">
    {{-- Lead Distribution --}}
    <div class="lg:col-span-12 xl:col-span-4 bg-surface-container-lowest p-6 sm:p-8 rounded-xl shadow-sm space-y-8 animate-fade-in">
        <div>
            <h3 class="font-bold text-lg text-on-surface">Distribusi Lead</h3>
            <p class="text-xs text-on-surface-variant uppercase tracking-widest mt-1">Berdasarkan Status</p>
        </div>

        @php
            $accTotal = $statusDistribution->sum('consultations_count');
            $accCumulative = 0;
        @endphp

        <div class="relative flex justify-center">
            <div class="relative w-40 h-40 sm:w-48 sm:h-48">
                <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                    @if($accTotal > 0)
                        @foreach($statusDistribution as $status)
                            @php
                                $pct = ($status->consultations_count / $accTotal) * 100;
                                $offset = -$accCumulative;
                                $accCumulative += $pct;
                            @endphp
                            <circle cx="18" cy="18" r="15.915" fill="transparent"
                                    stroke="{{ $status->color }}"
                                    stroke-width="4"
                                    stroke-dasharray="{{ $pct }} {{ 100 - $pct }}"
                                    stroke-dashoffset="{{ $offset }}"></circle>
                        @endforeach
                    @else
                        <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#dbe4e7" stroke-width="4" stroke-dasharray="100 0" stroke-dashoffset="0"></circle>
                    @endif
                </svg>
                <div class="absolute inset-0 flex flex-col items-center justify-center">
                    <span class="block text-xl sm:text-2xl font-bold font-headline">{{ number_format($accTotal) }}</span>
                    <span class="text-[10px] text-on-surface-variant font-bold uppercase tracking-tighter">Total Leads</span>
                </div>
            </div>
        </div>

        <div class="grid grid-cols-1 sm:grid-cols-2 xl:grid-cols-1 gap-4">
            @foreach($statusDistribution as $status)
            <div class="flex items-center justify-between">
                <div class="flex items-center gap-3">
                    <div class="w-3 h-3 rounded-full shrink-0" style="background-color: {{ $status->color }}"></div>
                    <span class="text-sm font-medium truncate max-w-[120px]">{{ $status->name }}</span>
                </div>
                <span class="text-sm font-bold text-on-surface">
                    {{ $accTotal > 0 ? round(($status->consultations_count / $accTotal) * 100) : 0 }}%
                </span>
            </div>
            @endforeach
        </div>
    </div>

    {{-- Latest Leads Table --}}
    <div class="lg:col-span-12 xl:col-span-8 bg-surface-container-lowest rounded-xl shadow-sm overflow-hidden animate-fade-in flex flex-col">
        <div class="p-6 sm:p-8 flex flex-col sm:flex-row justify-between items-start sm:items-center border-b border-surface-container-low/50 gap-4">
            <div>
                <h3 class="font-bold text-lg text-on-surface">Lead Terbaru</h3>
                <p class="text-sm text-on-surface-variant">Update terkini dari prospek masuk.</p>
            </div>
            <a href="{{ route('consultations.index') }}" class="text-primary font-bold text-sm hover:underline flex items-center gap-1">
                <span>Lihat Semua Lead</span>
                <x-icon name="arrow_forward" class="w-3.5 h-3.5" />
            </a>
        </div>
        <div class="table-scroll-mobile overflow-x-auto scrollbar-thin scrollbar-thumb-surface-container shadow-inner">
            <table class="w-full min-w-[600px] text-left border-collapse whitespace-nowrap">
                <thead class="bg-surface-container-low/50">
                    <tr>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Lead Name</th>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Status</th>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Date</th>
                        <th class="px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-right">Actions</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-low">
                    @forelse($latestLeads as $lead)
                    <tr class="hover:bg-surface-container-low/30 transition-colors">
                        <td class="px-6 sm:px-8 py-5">
                            <div class="flex items-center gap-3">
                                <div class="w-8 h-8 rounded-full bg-surface-container flex items-center justify-center font-bold text-xs text-on-surface-variant shrink-0">
                                    {{ strtoupper(substr($lead->client_name, 0, 2)) }}
                                </div>
                                <span class="font-bold text-on-surface text-sm truncate max-w-[150px]">{{ $lead->client_name }}</span>
                            </div>
                        </td>
                        <td class="px-6 sm:px-8 py-5">
                            <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider"
                                  style="{{ $lead->statusCategory?->chip_style ?? 'background-color: rgba(115, 124, 127, 0.14); color: #737C7F;' }}">
                                {{ $lead->statusCategory?->name ?? '-' }}
                            </span>
                        </td>
                        <td class="px-6 sm:px-8 py-5 text-sm text-on-surface-variant font-medium">
                            {{ $lead->consultation_date?->format('M d, Y') ?? '-' }}
                        </td>
                        <td class="px-6 sm:px-8 py-5 text-right">
                            <div class="flex justify-end gap-2">
                                <a href="{{ route('consultations.show', $lead) }}"
                                   class="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-on-surface-variant transition-colors" title="Detail">
                                    <x-icon name="visibility" class="w-[18px] h-[18px]" />
                                </a>
                                <a href="{{ route('consultations.edit', $lead) }}"
                                   class="w-8 h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-on-surface-variant transition-colors" title="Edit">
                                    <x-icon name="edit" class="w-[18px] h-[18px]" />
                                </a>
                            </div>
                        </td>
                    </tr>
                    @empty
                    <tr>
                        <td colspan="4" class="px-6 sm:px-8 py-10 text-center text-on-surface-variant">Belum ada data leads.</td>
                    </tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>
</div>

<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 mt-8">
    {{-- Needs Category Analysis --}}
    <div class="bg-surface-container-low p-6 sm:p-8 rounded-xl animate-fade-in shadow-sm">
        <div class="flex items-center gap-3 mb-6">
            <x-icon name="analytics" class="w-5 h-5 text-primary" />
            <div>
                <h3 class="font-bold text-on-surface">Analisis Kategori Kebutuhan</h3>
                <p class="text-[10px] text-on-surface-variant uppercase tracking-widest mt-0.5">Distribusi Kebutuhan Lead</p>
            </div>
        </div>
        <div class="space-y-4 max-h-[400px] overflow-y-auto scrollbar-thin scrollbar-thumb-surface-container pr-2 border-t border-surface-container-low/50 pt-4">
            @php 
                $maxNeeds = $needsDistribution->max('consultations_count'); 
                $totalNeeds = $needsDistribution->sum('consultations_count');
            @endphp
            @forelse($needsDistribution as $need)
                <div class="group">
                    <div class="flex justify-between items-end mb-1.5">
                        <span class="text-sm font-bold text-on-surface truncate max-w-[200px]" title="{{ $need->name }}">{{ $need->name }}</span>
                        <div class="text-right">
                            <span class="text-xs font-extrabold text-on-surface mr-1">{{ $need->consultations_count }}</span>
                            <span class="text-[10px] text-on-surface-variant">({{ $totalNeeds > 0 ? round(($need->consultations_count / $totalNeeds) * 100) : 0 }}%)</span>
                        </div>
                    </div>
                    <div class="w-full bg-surface-container-high rounded-full h-2 overflow-hidden shadow-inner">
                        <div class="bg-primary h-full rounded-full transition-all duration-1000 group-hover:brightness-110" style="width: {{ $maxNeeds > 0 ? ($need->consultations_count / $maxNeeds) * 100 : 0 }}%"></div>
                    </div>
                </div>
            @empty
                <div class="flex flex-col items-center justify-center py-10 text-on-surface-variant opacity-60">
                    <x-icon name="pie_chart" class="w-10 h-10 mb-2" />
                    <p class="text-sm font-medium">Belum ada data kategori.</p>
                </div>
            @endforelse
        </div>
    </div>

    {{-- Timeline activity --}}
    <div class="bg-surface-container-low p-6 sm:p-8 rounded-xl animate-fade-in shadow-sm">
        <div class="flex items-center gap-3 mb-6">
            <x-icon name="history" class="w-5 h-5 text-tertiary" />
            <div>
                <h3 class="font-bold text-on-surface">Riwayat Aktivitas Terkini</h3>
                <p class="text-[10px] text-on-surface-variant uppercase tracking-widest mt-0.5">Log Perubahan Status</p>
            </div>
        </div>
        <div class="space-y-6 relative before:absolute before:left-[11px] before:top-2 before:bottom-2 before:w-[1px] before:bg-outline-variant/30 overflow-hidden border-t border-surface-container-low/50 pt-4">
            @forelse($recentActivity as $activity)
            <div class="flex gap-4 relative">
                <div class="w-[23px] h-[23px] rounded-full bg-white border-4 z-10 shrink-0 shadow-sm" style="border-color: {{ $activity->statusCategory?->color ?? '#737c7f' }}"></div>
                <div class="min-w-0">
                    <p class="text-sm font-bold text-on-surface truncate pr-4">{{ $activity->client_name }}: {{ $activity->product_names_label }}</p>
                    <p class="text-[11px] text-on-surface-variant flex items-center gap-1.5 flex-wrap mt-0.5 font-medium">
                        <span class="px-2 py-0.5 rounded text-[9px] uppercase tracking-wider font-bold" style="background-color: {{ $activity->statusCategory?->color ?? '#737c7f' }}20; color: {{ $activity->statusCategory?->color ?? '#737c7f' }}">{{ $activity->statusCategory?->name }}</span>
                        <span class="w-1 h-1 rounded-full bg-outline-variant/50"></span>
                        <span>{{ $activity->updated_at->diffForHumans() }}</span>
                    </p>
                </div>
            </div>
            @empty
            <div class="flex flex-col items-center justify-center py-6 text-on-surface-variant opacity-60">
                <x-icon name="history_toggle_off" class="w-10 h-10 mb-2" />
                <p class="text-sm font-medium">Belum ada riwayat aktivitas.</p>
            </div>
            @endforelse
        </div>
    </div>
</div>
@endsection
