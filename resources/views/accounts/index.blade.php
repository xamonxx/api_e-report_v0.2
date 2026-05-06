@extends('layouts.app')
@section('title', 'Accounts')

@section('content')
<div class="page-header mb-6">
    <div class="page-header__content">
        <h2 class="text-2xl sm:text-3xl font-extrabold text-on-surface tracking-tight font-headline">Accounts</h2>
        <p class="text-sm sm:text-base text-on-surface-variant mt-1">Kelola seluruh akun interior.</p>
    </div>
    <div class="page-header__actions">
        <a href="{{ route('accounts.create') }}" class="w-full sm:w-auto bg-primary text-on-primary px-6 py-3 rounded-xl font-bold text-sm flex items-center justify-center gap-2 shadow-lg shadow-primary/20 hover:bg-primary-dim transition-all active:scale-[0.98]">
            <x-icon name="add_circle" class="w-4 h-4" />
            <span>Tambah Akun Baru</span>
        </a>
    </div>
</div>

{{-- Filters --}}
<div class="filter-card mb-6 no-print">
    <form method="GET" action="{{ route('accounts.index') }}" class="flex flex-col gap-4">
        <div class="filter-grid">
        <div>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2 px-1">Cari Akun</label>
            <div class="relative">
                <span class="absolute inset-y-0 left-3 flex items-center text-outline-variant">
                    <x-icon name="search" class="w-[18px] h-[18px]" />
                </span>
                <input type="text" name="search" value="{{ request('search') }}"
                       class="w-full bg-surface-container-high border-0 rounded-xl pl-10 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-primary/20"
                       placeholder="Cari nama akun..." />
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2 px-1">ID Akun</label>
            <div class="relative">
                <span class="absolute inset-y-0 left-3 flex items-center text-outline-variant">
                    <x-icon name="tag" class="w-[18px] h-[18px]" />
                </span>
                <input type="number" name="account_id" value="{{ request('account_id') }}"
                       class="w-full bg-surface-container-high border-0 rounded-xl pl-10 pr-4 py-2.5 text-sm focus:ring-2 focus:ring-primary/20"
                       placeholder="ID..." min="1" />
            </div>
        </div>
        <div>
            <label class="block text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mb-2 px-1">Kategori Akun</label>
            <div x-data="searchableSelect(@js(collect([['value' => '', 'label' => 'Semua Kategori']])->concat(collect($categories)->map(fn($category) => ['value' => (string) $category, 'label' => (string) $category])->values())), @js((string) request('category', '')))"
                 @click.outside="close()"
                 @keydown.escape.prevent.stop="close()"
                 class="relative">
                <input type="hidden" name="category" :value="selected">
                <button type="button"
                        @click="toggle()"
                        class="w-full bg-surface-container-high rounded-xl pl-4 pr-12 py-2.5 text-left text-sm shadow-inner transition focus:outline-none focus:ring-2 focus:ring-primary/20"
                        :class="open ? 'ring-2 ring-primary/20' : ''"
                        :aria-expanded="open.toString()"
                        aria-haspopup="listbox">
                    <span class="block truncate font-semibold text-on-surface"
                          x-text="selectedLabel('Semua Kategori')"></span>
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
                               placeholder="Cari kategori..." autocomplete="off">
                    </div>
                    <div class="max-h-60 overflow-y-auto p-1.5">
                        <template x-if="filteredOptions().length === 0">
                            <div class="px-4 py-3 text-sm text-outline-variant">Kategori tidak ditemukan.</div>
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

        <div class="filter-actions">
            <button type="submit" class="w-full sm:w-auto bg-primary/10 text-primary px-6 py-2.5 rounded-xl text-sm font-bold hover:bg-primary/20 transition-all active:scale-[0.98]">
                Filter
            </button>
            @if(request()->hasAny(['search', 'category', 'account_id']))
            <a href="{{ route('accounts.index') }}" class="w-full sm:w-auto text-center text-on-surface-variant text-xs sm:text-sm hover:text-error transition-colors font-bold px-2 py-2">Reset</a>
            @endif
        </div>
    </form>
</div>

<div class="grid grid-cols-2 lg:grid-cols-3 xl:grid-cols-4 gap-3 sm:gap-6 stagger-children">
    @foreach($accounts as $account)
    <div class="bg-surface-container-lowest p-4 sm:p-5 rounded-2xl shadow-sm hover-lift transition-all group relative border border-surface-container-low max-w-full overflow-hidden flex flex-col">
        <div class="flex justify-between items-start mb-3 sm:mb-4">
            @if($account->logo_path)
                <img src="{{ Storage::url($account->logo_path) }}" alt="{{ $account->name }}" loading="lazy" class="w-10 h-10 sm:w-12 sm:h-12 rounded-lg object-cover bg-surface shadow-sm ring-2 ring-surface">
            @else
                <div class="p-2 sm:p-2.5 bg-primary-container/30 rounded-lg group-hover:bg-primary group-hover:text-on-primary transition-colors text-primary shrink-0">
                    <x-icon name="domain" class="w-5 h-5" />
                </div>
            @endif
            <div class="flex gap-1 no-print">
                <a href="{{ route('accounts.edit', $account) }}" class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg hover:bg-surface-container flex items-center justify-center text-on-surface-variant hover:text-primary transition-all active:scale-90" title="Edit">
                    <x-icon name="edit" class="w-4 h-4" />
                </a>
                <form id="delete-acct-{{ $account->id }}" method="POST" action="{{ route('accounts.destroy', $account) }}">
                    @csrf @method('DELETE')
                    <button type="button" onclick="confirmDeleteAccount('delete-acct-{{ $account->id }}', {{ \Illuminate\Support\Js::from($account->name) }})"
                            class="w-7 h-7 sm:w-8 sm:h-8 rounded-lg hover:bg-error/10 flex items-center justify-center text-on-surface-variant hover:text-error transition-all active:scale-90"
                            title="Hapus">
                        <x-icon name="delete" class="w-4 h-4" />
                    </button>
                </form>
            </div>
        </div>
        
        <div class="space-y-0.5 mb-2 sm:mb-3">
            <h3 class="font-bold text-on-surface text-sm sm:text-base group-hover:text-primary transition-colors leading-tight truncate pr-2">
                {{ $account->name }}
            </h3>
            <div class="flex items-center flex-wrap gap-1">
                <span class="text-[8px] sm:text-[9px] font-mono font-bold bg-primary-container/40 text-primary px-1.5 py-0.5 rounded-md">ID: {{ str_pad($account->id, 3, '0', STR_PAD_LEFT) }}</span>
            </div>
        </div>
        
        <p class="hidden sm:block text-[11px] sm:text-xs text-on-surface-variant mb-3 sm:mb-4 line-clamp-1 leading-relaxed">{{ $account->description ?? '-' }}</p>

        <div class="grid grid-cols-2 gap-2 sm:gap-3 mb-3 sm:mb-4 mt-auto">
            <div class="bg-surface-container-low rounded-xl p-2 flex flex-col items-center text-center shadow-inner">
                <p class="text-[8px] sm:text-[9px] font-bold text-on-surface-variant uppercase tracking-widest opacity-60">Lead</p>
                <p class="text-base sm:text-xl font-extrabold font-headline text-on-surface leading-none mt-1">{{ number_format($account->consultations_count) }}</p>
            </div>
            <div class="bg-surface-container-low rounded-xl p-2 flex flex-col items-center text-center shadow-inner">
                <p class="text-[8px] sm:text-[9px] font-bold text-on-surface-variant uppercase tracking-widest opacity-60">Konversi</p>
                <p class="text-base sm:text-xl font-extrabold font-headline text-tertiary leading-none mt-1">{{ $account->conversion_rate }}%</p>
            </div>
        </div>

        <div class="space-y-1 sm:space-y-1.5 mb-1">
            @php $progress = min($account->target_progress, 100); @endphp
            <div class="flex justify-between items-center text-[8px] sm:text-[9px] font-bold uppercase tracking-wider text-on-surface-variant px-0.5">
                <span>Target</span>
                <span class="shrink-0">{{ $account->lead_count }}/{{ $account->target_leads }}</span>
            </div>
            <div class="w-full bg-surface-container h-1.5 sm:h-1.5 rounded-full overflow-hidden shadow-inner">
                <div class="h-full bg-primary rounded-full transition-all duration-700 ease-out" style="width: {{ $progress }}%"></div>
            </div>
        </div>

        @if($account->admins->count() > 0)
        <div class="mt-3 sm:mt-4 pt-3 border-t border-surface-container-low/50">
            <p class="text-[9px] font-bold text-on-surface-variant uppercase tracking-widest mb-2 px-1">Admin</p>
            <div class="flex gap-1.5 flex-wrap">
                @foreach($account->admins as $admin)
                <div class="flex items-center gap-1 bg-primary-container/20 text-primary px-2 py-1 rounded-md text-[9px] font-bold border border-primary/5 max-w-full">
                    <x-icon name="person" class="w-3 h-3 shrink-0" />
                    <span class="truncate">{{ $admin->name }}</span>
                </div>
                @endforeach
            </div>
        </div>
        @else
        <div class="mt-3 sm:mt-4 pt-3 border-t border-surface-container-low/50">
            <p class="text-[9px] font-bold text-on-surface-variant uppercase tracking-widest mb-2 px-1">Admin</p>
            <div class="flex gap-1.5 flex-wrap">
                <div class="flex items-center gap-1 bg-surface-container/50 text-on-surface-variant px-2 py-1 rounded-md text-[9px] font-bold max-w-full">
                    <x-icon name="error" class="w-3 h-3 shrink-0" />
                    <span class="truncate">Belum ada admin</span>
                </div>
            </div>
        </div>
        @endif
    </div>
    @endforeach
</div>


<div class="mt-8">
    {{ $accounts->appends(request()->query())->links() }}
</div>

@push('scripts')
<script>
    function confirmDeleteAccount(formId, accountName) {
        window.withSwal(async (Swal) => {
            const result = await Swal.fire({
                title: 'Hapus akun pusat ini?',
                text: 'Tindakan ini akan menghapus akun "' + accountName + '" beserta seluruh data konsultasi, tim, dan histori yang terhubung secara permanen!',
                icon: 'warning',
                showCancelButton: true,
                confirmButtonColor: '#9f403d',
                cancelButtonColor: '#737c7f',
                confirmButtonText: 'Ya, hapus!',
                cancelButtonText: 'Batal',
                customClass: {
                    popup: 'rounded-2xl shadow-2xl',
                    title: 'text-xl font-headline font-bold text-on-surface',
                    confirmButton: 'bg-error hover:bg-error-dim rounded-xl px-8 py-3 font-bold',
                    cancelButton: 'bg-outline hover:bg-outline-variant rounded-xl px-8 py-3 font-bold'
                }
            });

            if (result.isConfirmed) {
                document.getElementById(formId).submit();
            }
        });
    }
</script>
@endpush
@endsection
