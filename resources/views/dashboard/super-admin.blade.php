@extends('layouts.app')
@section('title', 'Dashboard Pusat')

@section('content')
{{-- React Dashboard Mount Point --}}
<div id="dashboard-root" data-role="{{ auth()->user()->role->value }}" class="hidden"></div>

{{-- Row 1: Metrics --}}
<div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 sm:gap-6 stagger-children">
    {{-- Total Leads --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-primary-container/30 rounded-lg group-hover:bg-primary group-hover:text-on-primary transition-colors shrink-0">
                <x-icon name="groups" class="w-5 h-5" />
            </div>
            <span class="text-[10px] font-bold {{ $growthPercent >= 0 ? 'text-tertiary bg-tertiary-container/20' : 'text-error bg-error-container/20' }} px-2 py-0.5 rounded-full uppercase tracking-tighter">
                {{ $growthPercent >= 0 ? '+' : '' }}{{ $growthPercent }}%
            </span>
        </div>
        <h3 class="text-on-surface-variant text-[10px] sm:text-xs font-medium uppercase tracking-wider mb-1">Total Lead</h3>
        <p class="text-2xl sm:text-3xl font-extrabold font-headline text-on-surface">{{ number_format($totalLeads) }}</p>
    </div>

    {{-- Avg Conversion Rate --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-inverse-primary/20 rounded-lg group-hover:bg-inverse-primary group-hover:text-on-primary transition-colors shrink-0">
                <x-icon name="analytics" class="w-5 h-5" />
            </div>
        </div>
        <h3 class="text-on-surface-variant text-[10px] sm:text-xs font-medium uppercase tracking-wider mb-1">Rata-rata Konversi</h3>
        <p class="text-2xl sm:text-3xl font-extrabold font-headline text-on-surface">{{ $avgConversion }}%</p>
    </div>

    {{-- Accounts Status --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-secondary-container/50 rounded-lg group-hover:bg-secondary group-hover:text-on-primary transition-colors shrink-0">
                <x-icon name="domain" class="w-5 h-5" />
            </div>
            <span class="text-[10px] font-bold text-on-surface-variant bg-surface-container-high px-2 py-0.5 rounded-full uppercase tracking-tighter hidden sm:inline">Stable</span>
        </div>
        <h3 class="text-on-surface-variant text-[10px] sm:text-xs font-medium uppercase tracking-wider mb-1">Total Semua Akun</h3>
        <p class="text-2xl sm:text-3xl font-extrabold font-headline text-on-surface mb-2 leading-none">{{ $totalAccounts }}</p>
        <div class="flex items-center gap-3 mt-auto">
            <div class="flex items-center gap-1 min-w-0" title="Akun dengan Admin assigned">
                <span class="w-1.5 h-1.5 rounded-full bg-secondary shrink-0"></span>
                <span class="text-[9px] sm:text-[10px] text-on-surface-variant whitespace-nowrap"><span class="font-bold text-on-surface">{{ $activeAccounts }}</span> Aktif</span>
            </div>
            <div class="w-px h-3 bg-surface-container-high shrink-0"></div>
            <div class="flex items-center gap-1 min-w-0" title="Akun tanpa Admin (Inactive)">
                <span class="w-1.5 h-1.5 rounded-full bg-outline-variant shrink-0"></span>
                <span class="text-[9px] sm:text-[10px] text-on-surface-variant whitespace-nowrap"><span class="font-bold text-on-surface">{{ $inactiveAccounts }}</span> Tidak Aktif</span>
            </div>
        </div>
    </div>

    {{-- Top Performing Admin --}}
    <div class="bg-surface-container-lowest p-6 rounded-xl shadow-sm border border-transparent hover:border-primary/10 transition-all group hover-lift animate-fade-in">
        <div class="flex justify-between items-start mb-4">
            <div class="p-2 bg-tertiary-container/30 rounded-lg group-hover:bg-tertiary group-hover:text-on-primary transition-colors shrink-0">
                <x-icon name="award_star" class="w-5 h-5" />
            </div>
        </div>
        <h3 class="text-on-surface-variant text-[10px] sm:text-xs font-medium uppercase tracking-wider mb-1">Admin Terbaik</h3>
        @if($topAdmin)
        <div class="flex items-center gap-2 sm:gap-3 mt-1 overflow-hidden">
            <div class="w-7 h-7 sm:w-8 sm:h-8 rounded-full bg-tertiary-container flex items-center justify-center text-tertiary font-bold text-xs shrink-0">
                {{ strtoupper(substr($topAdmin->name, 0, 2)) }}
            </div>
            <p class="text-base sm:text-lg font-bold text-on-surface truncate pr-2">{{ $topAdmin->name }}</p>
        </div>
        @else
        <p class="text-sm text-on-surface-variant mt-1">—</p>
        @endif
    </div>
</div>

{{-- Row 2: Leaderboard + Doughnut --}}
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6 sm:gap-8">
    {{-- Leaderboard Table --}}
    <div class="lg:col-span-2 bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden flex flex-col animate-fade-in">
        <div class="px-5 sm:px-8 py-5 sm:py-6 flex flex-col sm:flex-row justify-between items-start sm:items-center bg-surface-container-low/70 supports-[backdrop-filter]:bg-surface-container-low/60 border-b border-surface-container-low/50 gap-3 sm:gap-4">
            <div>
                <h2 class="text-xl font-bold font-headline text-on-surface">Peringkat Akun</h2>
                <p class="text-xs text-on-surface-variant">Kinerja berdasarkan target konversi. Geser di dalam panel untuk melihat semua akun.</p>
            </div>
            <a href="{{ route('accounts.index') }}" class="text-primary text-sm font-semibold hover:underline">Lihat Semua Akun</a>
        </div>
        <div class="table-scroll-mobile overflow-x-auto overflow-y-auto max-h-[28rem] lg:max-h-[32rem] scrollbar-thin scrollbar-thumb-surface-container shadow-inner">
            <table class="w-full min-w-[600px] text-left border-collapse whitespace-nowrap">
                <thead>
                    <tr class="bg-surface-container-low/95 backdrop-blur supports-[backdrop-filter]:bg-surface-container-low/85">
                        <th class="sticky top-0 z-10 bg-surface-container-low/95 px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-center">Peringkat</th>
                        <th class="sticky top-0 z-10 bg-surface-container-low/95 px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest font-headline">Nama Akun</th>
                        <th class="sticky top-0 z-10 bg-surface-container-low/95 px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-center">Lead</th>
                        <th class="sticky top-0 z-10 bg-surface-container-low/95 px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Progres Target</th>
                        <th class="sticky top-0 z-10 bg-surface-container-low/95 px-6 sm:px-8 py-4 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-right">Konversi</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-surface-container-low">
                    @foreach($accounts as $index => $account)
                    <tr class="hover:bg-surface-container-low/30 transition-colors">
                        <td class="px-6 sm:px-8 py-5">
                            @php
                                $badgeColors = ['bg-amber-100 text-amber-700', 'bg-slate-200 text-slate-700', 'bg-orange-100 text-orange-700'];
                                $badgeClass = $badgeColors[$index] ?? 'bg-surface-container text-on-surface-variant';
                            @endphp
                            <div class="w-8 h-8 rounded-full {{ $badgeClass }} flex items-center justify-center font-bold text-sm mx-auto">{{ $index + 1 }}</div>
                        </td>
                        <td class="px-6 sm:px-8 py-5">
                            <div class="font-semibold text-on-surface text-sm truncate max-w-[200px]">{{ $account->name }}</div>
                            <div class="text-[10px] text-on-surface-variant truncate max-w-[180px]">{{ $account->city }} • {{ $account->province }}</div>
                        </td>
                        <td class="px-6 sm:px-8 py-5 text-center">
                            <span class="font-bold text-on-surface leading-none">{{ $account->lead_count }}</span>
                        </td>
                        <td class="px-6 sm:px-8 py-5">
                            @php $progress = min($account->target_progress, 100); @endphp
                            <div class="w-24 sm:w-32 h-1.5 bg-surface-container-high rounded-full overflow-hidden">
                                <div class="h-full bg-primary rounded-full transition-all" style="width: {{ $progress }}%"></div>
                            </div>
                            <span class="text-[10px] font-bold mt-1.5 block text-on-surface-variant">{{ $account->target_progress }}% target</span>
                        </td>
                        <td class="px-6 sm:px-8 py-5 text-right font-headline">
                            <span class="text-sm font-bold {{ $account->conversion_rate > 20 ? 'text-tertiary' : 'text-on-surface-variant' }}">
                                {{ $account->conversion_rate }}%
                            </span>
                        </td>
                    </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>

    {{-- Doughnut Chart --}}
    <div class="lg:col-span-1 bg-surface-container-lowest p-6 sm:p-8 rounded-2xl shadow-sm flex flex-col items-center animate-fade-in relative">
        <div class="w-full mb-8">
            <h2 class="text-xl font-bold font-headline text-on-surface">Distribusi Global</h2>
            <p class="text-xs text-on-surface-variant">Ringkasan kesehatan data lead</p>
        </div>

        @php
            $total = $statusDistribution->sum('consultations_count');
            $cumulative = 0;
        @endphp

        <div class="relative w-40 h-40 sm:w-48 sm:h-48 mb-8 shrink-0">
            <svg class="w-full h-full transform -rotate-90" viewBox="0 0 36 36">
                @if($total > 0)
                    @foreach($statusDistribution as $status)
                        @php
                            $pct = ($status->consultations_count / $total) * 100;
                            $offset = -$cumulative;
                            $cumulative += $pct;
                        @endphp
                        <circle cx="18" cy="18" r="15.915" fill="transparent"
                                stroke="{{ $status->color }}"
                                stroke-width="3"
                                stroke-dasharray="{{ $pct }} {{ 100 - $pct }}"
                                stroke-dashoffset="{{ $offset }}"></circle>
                    @endforeach
                @else
                    <circle cx="18" cy="18" r="15.915" fill="transparent" stroke="#dbe4e7" stroke-width="3" stroke-dasharray="100 0" stroke-dashoffset="0"></circle>
                @endif
            </svg>
            <div class="absolute inset-0 flex flex-col items-center justify-center">
                <span class="text-2xl font-extrabold text-on-surface font-headline">{{ number_format($total) }}</span>
                <span class="text-[9px] text-on-surface-variant font-bold uppercase tracking-tighter">Total Lead</span>
            </div>
        </div>

        <div class="w-full grid grid-cols-2 gap-4">
            @foreach($statusDistribution as $status)
            <div class="flex items-start gap-2 min-w-0">
                <span class="w-2 h-2 rounded-full shrink-0 mt-1" style="background-color: {{ $status->color }}"></span>
                <div class="min-w-0">
                    <p class="text-[10px] font-bold text-on-surface truncate">{{ $status->name }}</p>
                    <p class="text-[10px] text-on-surface-variant opacity-60 leading-tight">{{ $status->consultations_count }}</p>
                </div>
            </div>
            @endforeach
        </div>
    </div>
</div>

{{-- Row 3: Report Attendance Monitoring --}}
<div class="bg-surface-container-lowest rounded-2xl shadow-sm overflow-hidden flex flex-col animate-fade-in mt-8">
    <div class="px-5 sm:px-8 py-5 sm:py-6 flex flex-col xl:flex-row justify-between items-start xl:items-center bg-surface-container-low/70 supports-[backdrop-filter]:bg-surface-container-low/60 border-b border-surface-container-low/50 gap-4 sm:gap-6">
        <div>
            <h2 class="text-xl font-bold font-headline text-on-surface">Monitoring Laporan Harian Admin</h2>
            <p class="text-xs text-on-surface-variant">Absensi report untuk tanggal: {{ $today->translatedFormat('d F Y') }}. Tabel dibuat lebih ringkas dan bisa digeser di dalam panel.</p>
        </div>
        <div class="flex flex-wrap items-center gap-3 sm:gap-4 text-[10px] sm:text-xs font-semibold">
            <span class="flex items-center gap-1.5 text-green-700 whitespace-nowrap"><span class="w-3 h-3 rounded bg-green-500 shrink-0"></span> Ada WA</span>
            <span class="flex items-center gap-1.5 text-yellow-700 whitespace-nowrap"><span class="w-3 h-3 rounded bg-yellow-500 shrink-0"></span> 0 Data WA</span>
            <span class="flex items-center gap-1.5 text-sky-700 whitespace-nowrap"><span class="w-3 h-3 rounded bg-sky-500 shrink-0"></span> Libur</span>
            <span class="flex items-center gap-1.5 text-error whitespace-nowrap"><span class="w-3 h-3 rounded bg-error shrink-0"></span> Tidak Laporan</span>
        </div>
    </div>
    <div class="table-scroll-mobile overflow-x-auto overflow-y-auto max-h-[30rem] scrollbar-thin scrollbar-thumb-surface-container shadow-inner">
        <table class="w-full min-w-[700px] text-left border-collapse whitespace-nowrap">
            <thead>
                <tr class="bg-surface-container-low/95 backdrop-blur supports-[backdrop-filter]:bg-surface-container-low/85">
                    <th class="sticky top-0 z-10 bg-surface-container-low/95 px-4 sm:px-5 py-3 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest text-center">Waktu</th>
                    <th class="sticky top-0 z-10 bg-surface-container-low/95 px-4 sm:px-5 py-3 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Admin</th>
                    <th class="sticky top-0 z-10 bg-surface-container-low/95 px-4 sm:px-5 py-3 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Akun</th>
                    <th class="sticky top-0 z-10 bg-surface-container-low/95 px-4 sm:px-5 py-3 text-[10px] font-bold text-on-surface-variant uppercase tracking-widest">Status</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-surface-container-low">
                @foreach($adminAttendances as $att)
                <tr class="hover:bg-surface-container-low/30 transition-colors">
                    <td class="px-4 sm:px-5 py-3 text-center">
                        @if($att->has_reported)
                            <div class="inline-flex flex-col items-center rounded-xl bg-surface px-3 py-2 border border-surface-container-low">
                                <span class="text-on-surface font-bold text-sm leading-none">{{ $att->reported_at->format('H:i') }}</span>
                                <span class="text-[9px] text-on-surface-variant mt-1">WIB</span>
                            </div>
                        @else
                            <span class="text-error/30 font-bold text-xl leading-none">—</span>
                        @endif
                    </td>
                    <td class="px-4 sm:px-5 py-3">
                        <div class="flex items-center gap-3">
                            <div class="w-8 h-8 rounded-full bg-surface-container flex items-center justify-center font-bold text-xs text-on-surface-variant shrink-0">
                                {{ strtoupper(substr($att->admin->name, 0, 2)) }}
                            </div>
                            <div class="min-w-0">
                                <span class="font-bold text-on-surface text-sm truncate max-w-[150px] block">{{ $att->admin->name }}</span>
                                <span class="text-[9px] text-on-surface-variant uppercase tracking-wider">Admin Akun</span>
                            </div>
                        </div>
                    </td>
                    <td class="px-4 sm:px-5 py-3">
                        <span class="text-xs font-medium text-on-surface-variant truncate max-w-[160px] block">{{ $att->account?->name ?? 'Pusat/Lain' }}</span>
                    </td>
                    <td class="px-4 sm:px-5 py-3">
                        <div class="flex justify-start">
                            @if($att->has_reported)
                                @if($att->report_category === 'ada_wa')
                                    <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-green-100 text-green-700 border border-green-200 flex items-center gap-1.5">
                                        <x-icon name="done" class="w-3 h-3" /> Ada WA Baru
                                    </span>
                                @elseif($att->report_category === 'nol_wa')
                                    <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-yellow-100 text-yellow-700 border border-yellow-200 flex items-center gap-1.5">
                                        <x-icon name="horizontal_rule" class="w-3 h-3" /> 0 Data WA
                                    </span>
                                @elseif($att->report_category === 'libur_susulan')
                                    <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-sky-100 text-sky-700 border border-sky-200 flex items-center gap-1.5">
                                        <x-icon name="event_note" class="w-3 h-3" /> Susulan / Libur
                                    </span>
                                @else
                                    <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-tertiary-container/30 text-tertiary border border-tertiary/20 flex items-center gap-1.5">
                                        <x-icon name="done" class="w-3 h-3" /> Recorded
                                    </span>
                                @endif
                            @else
                                <span class="px-3 py-1 rounded-full text-[10px] font-bold uppercase tracking-wider bg-error/10 text-error border border-error/10 flex items-center gap-1.5">
                                    <x-icon name="close" class="w-3 h-3" /> Belum Laporan
                                </span>
                            @endif
                        </div>
                    </td>
                </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>

{{-- Row 4: Activity & Needs Analysis --}}
<div class="grid grid-cols-1 lg:grid-cols-2 gap-6 sm:gap-8 mt-8">
    {{-- Recent Activity --}}
    <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm animate-fade-in flex flex-col h-full">
        <div class="flex justify-between items-center mb-6">
            <h3 class="font-bold text-on-surface">Konsultasi Terbaru</h3>
            <span class="flex items-center gap-1.5 text-[10px] font-bold text-tertiary uppercase hidden sm:flex">
                <span class="w-1.5 h-1.5 rounded-full bg-tertiary animate-pulse-soft"></span>
                Live Update
            </span>
        </div>
        <div class="space-y-4 flex-1">
            @forelse($recentConsultations as $c)
            <div class="flex items-start gap-3 p-3 rounded-xl hover:bg-surface-container-low transition-colors group">
                <div class="p-2 rounded-lg shrink-0 mt-0.5" style="background-color: {{ $c->statusCategory?->color ?? '#737c7f' }}20">
                    <x-icon name="person" class="w-3.5 h-3.5" style="color: {{ $c->statusCategory?->color ?? '#737c7f' }}" />
                </div>
                <div class="flex-1 min-w-0">
                    <p class="text-sm font-bold truncate pr-2">{{ $c->client_name }}</p>
                    <p class="text-[10px] text-on-surface-variant truncate">{{ $c->account?->name }} • {{ $c->product_names_label }}</p>
                </div>
                <span class="text-[10px] text-outline-variant whitespace-nowrap group-hover:text-primary-dim transition-colors">{{ $c->created_at->diffForHumans() }}</span>
            </div>
            @empty
            <p class="text-sm text-on-surface-variant text-center py-10">Belum ada data konsultasi.</p>
            @endforelse
        </div>
    </div>

    {{-- Needs Category Analysis --}}
    <div class="bg-surface-container-lowest p-6 rounded-2xl shadow-sm animate-fade-in flex flex-col h-full">
        <div class="flex items-center gap-3 mb-6">
            <x-icon name="analytics" class="w-5 h-5 text-primary" />
            <div>
                <h3 class="font-bold text-on-surface">Kategori Kebutuhan</h3>
                <p class="text-[10px] text-on-surface-variant uppercase tracking-widest mt-0.5">Distribusi Global Lead</p>
            </div>
        </div>
        <div class="space-y-4 max-h-[300px] overflow-y-auto scrollbar-thin scrollbar-thumb-surface-container pr-2 border-t border-surface-container-low/50 pt-4 flex-1">
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
</div>

{{-- Row 5: Export Banner --}}
<div class="mt-8 bg-primary-fixed/30 p-6 md:p-10 rounded-2xl border border-primary/10 relative overflow-hidden flex flex-col lg:flex-row justify-between items-start lg:items-center gap-8 animate-fade-in shadow-sm">
    <div class="relative z-10 max-w-2xl">
        <h2 class="text-2xl md:text-3xl font-bold font-headline text-primary mb-2 md:mb-3 leading-tight">Analisis Data Global</h2>
        <p class="text-on-surface-variant text-sm md:text-base leading-relaxed">Ekspor seluruh data leads untuk kebutuhan insight pemasaran dan mengevaluasi kinerja seluruh tim interior dari akun maupun pusat.</p>
    </div>
    <div class="relative z-10 flex flex-col sm:flex-row gap-4 w-full lg:w-auto shrink-0 no-print">
        <a href="{{ route('export.csv') }}"
           class="bg-primary text-on-primary px-8 py-3.5 rounded-xl text-sm font-bold shadow-xl shadow-primary/20 flex items-center justify-center gap-2 hover:bg-primary-dim transition-all hover:scale-[1.02] active:scale-[0.98] w-full sm:w-auto">
            <x-icon name="download" class="w-4 h-4" />
            <span>Download CSV</span>
        </a>
        <button onclick="window.print()" class="border-2 border-primary border-dashed text-primary bg-primary/5 px-8 py-3.5 rounded-xl text-sm font-bold hover:bg-primary/10 transition-all flex items-center justify-center gap-2 w-full sm:w-auto">
            <x-icon name="print" class="w-4 h-4" />
            <span>Cetak Rapot</span>
        </button>
    </div>
    <div class="absolute -left-16 -bottom-16 w-64 h-64 bg-primary/10 rounded-full blur-3xl pointer-events-none"></div>
    <div class="absolute right-10 top-1/2 -translate-y-1/2 opacity-5 pointer-events-none hidden lg:block">
        <x-icon name="data_thresholding" class="w-36 h-36 transform -rotate-12" />
    </div>
</div>
@endsection
