{{-- Sidebar Component --}}
@php
    $currentRoute = Route::currentRouteName();
    $user = auth()->user();
@endphp

<aside role="navigation" aria-label="Sidebar navigasi" class="glass-sidebar mobile-sidebar-panel h-[100dvh] lg:h-screen flex flex-col py-6 w-full shrink-0 bg-surface-container-lowest lg:bg-surface-container-lowest/96 backdrop-blur-md border-r border-surface-container lg:border-r-0 overflow-x-hidden">
    {{-- Close Button for Mobile --}}
    <button type="button" @click="sidebarOpen = false" aria-label="Tutup sidebar" title="Tutup sidebar" class="lg:hidden absolute top-4 right-4 w-10 h-10 flex items-center justify-center text-on-surface-variant">
        <x-icon name="close" class="w-5 h-5" />
    </button>

    {{-- Logo --}}
    <div class="px-5 sm:px-6 mb-8 sm:mb-10">
        <div class="flex items-center {{ $isSidebarOpen ? 'gap-3 justify-start' : 'justify-center' }}" :class="(isMobile || sidebarOpen) ? '!gap-3 !justify-start' : '!justify-center'">
            <div class="w-10 h-10 rounded-xl border border-primary/20 bg-primary/12 flex items-center justify-center text-primary shadow-[0_10px_24px_rgb(var(--color-primary-rgb)/0.12)] shrink-0 transition-transform">
                <x-icon name="architecture" class="w-5 h-5" />
            </div>
            <div x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="overflow-hidden">
                <h1 class="font-bold text-on-surface text-sm tracking-tight leading-none font-headline whitespace-nowrap">E-REPORT</h1>
                <p class="text-[10px] text-on-surface-variant uppercase tracking-widest mt-1 whitespace-nowrap">Data Konsultasi</p>
            </div>
        </div>
    </div>

    {{-- Navigation --}}
    <nav aria-label="Navigasi utama" class="flex-1 px-3 sm:px-4 space-y-1 overflow-y-auto custom-scrollbar">
        <a href="{{ route('dashboard') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Beranda" title="Beranda"
           class="flex items-center py-3 rounded-xl border transition-all duration-200 font-headline text-sm tracking-tight
                  {{ $currentRoute === 'dashboard' ? 'text-primary font-semibold border-primary/20 bg-primary/10 shadow-[inset_0_1px_0_rgb(255_255_255/0.03)]' : 'border-transparent text-on-surface-variant hover:border-surface-container-high hover:bg-surface-container-high/70 hover:text-on-surface' }}
                  {{ $isSidebarOpen ? 'gap-3 px-4 justify-start' : 'justify-center px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-3 !px-4 !justify-start' : '!justify-center !px-0'">
            <x-icon name="dashboard" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">Beranda</span>
        </a>

        @if($user->isSuperAdmin())
        <a href="{{ route('accounts.index') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Akun" title="Akun"
           class="flex items-center py-3 rounded-xl border transition-all duration-200 font-headline text-sm tracking-tight
                  {{ str_starts_with($currentRoute, 'accounts') ? 'text-primary font-semibold border-primary/20 bg-primary/10 shadow-[inset_0_1px_0_rgb(255_255_255/0.03)]' : 'border-transparent text-on-surface-variant hover:border-surface-container-high hover:bg-surface-container-high/70 hover:text-on-surface' }}
                  {{ $isSidebarOpen ? 'gap-3 px-4 justify-start' : 'justify-center px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-3 !px-4 !justify-start' : '!justify-center !px-0'">
            <x-icon name="folder_shared" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">AKUN</span>
        </a>
        @endif

        <a href="{{ route('consultations.index') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Daftar Lead" title="Daftar Lead"
           class="flex items-center py-3 rounded-xl border transition-all duration-200 font-headline text-sm tracking-tight
                  {{ str_starts_with($currentRoute, 'consultations') ? 'text-primary font-semibold border-primary/20 bg-primary/10 shadow-[inset_0_1px_0_rgb(255_255_255/0.03)]' : 'border-transparent text-on-surface-variant hover:border-surface-container-high hover:bg-surface-container-high/70 hover:text-on-surface' }}
                  {{ $isSidebarOpen ? 'gap-3 px-4 justify-start' : 'justify-center px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-3 !px-4 !justify-start' : '!justify-center !px-0'">
            <x-icon name="person_search" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">Daftar Lead</span>
        </a>

        <a href="{{ route('analytics') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Analisis" title="Analisis"
           class="flex items-center py-3 rounded-xl border transition-all duration-200 font-headline text-sm tracking-tight
                  {{ $currentRoute === 'analytics' ? 'text-primary font-semibold border-primary/20 bg-primary/10 shadow-[inset_0_1px_0_rgb(255_255_255/0.03)]' : 'border-transparent text-on-surface-variant hover:border-surface-container-high hover:bg-surface-container-high/70 hover:text-on-surface' }}
                  {{ $isSidebarOpen ? 'gap-3 px-4 justify-start' : 'justify-center px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-3 !px-4 !justify-start' : '!justify-center !px-0'">
            <x-icon name="leaderboard" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">Analisis</span>
        </a>

        @if($user->isSuperAdmin())
        <a href="{{ route('report-attendances.index') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Monitoring Laporan" title="Monitoring Laporan"
           class="flex items-center py-3 rounded-xl border transition-all duration-200 font-headline text-sm tracking-tight
                  {{ str_starts_with($currentRoute, 'report-attendances') ? 'text-primary font-semibold border-primary/20 bg-primary/10 shadow-[inset_0_1px_0_rgb(255_255_255/0.03)]' : 'border-transparent text-on-surface-variant hover:border-surface-container-high hover:bg-surface-container-high/70 hover:text-on-surface' }}
                  {{ $isSidebarOpen ? 'gap-3 px-4 justify-start' : 'justify-center px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-3 !px-4 !justify-start' : '!justify-center !px-0'">
            <x-icon name="assignment_turned_in" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">Monitoring Laporan</span>
        </a>
        @endif

        @if($user->isSuperAdmin())
        <a href="{{ route('master-data.index') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Master Data" title="Master Data"
           class="flex items-center py-3 rounded-xl border transition-all duration-200 font-headline text-sm tracking-tight
                  {{ str_starts_with($currentRoute, 'master-data') ? 'text-primary font-semibold border-primary/20 bg-primary/10 shadow-[inset_0_1px_0_rgb(255_255_255/0.03)]' : 'border-transparent text-on-surface-variant hover:border-surface-container-high hover:bg-surface-container-high/70 hover:text-on-surface' }}
                  {{ $isSidebarOpen ? 'gap-3 px-4 justify-start' : 'justify-center px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-3 !px-4 !justify-start' : '!justify-center !px-0'">
            <x-icon name="database" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">Master Data</span>
        </a>
        @endif

        <a href="{{ route('settings') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Pengaturan" title="Pengaturan"
           class="flex items-center py-3 rounded-xl border transition-all duration-200 font-headline text-sm tracking-tight
                  {{ $currentRoute === 'settings' ? 'text-primary font-semibold border-primary/20 bg-primary/10 shadow-[inset_0_1px_0_rgb(255_255_255/0.03)]' : 'border-transparent text-on-surface-variant hover:border-surface-container-high hover:bg-surface-container-high/70 hover:text-on-surface' }}
                  {{ $isSidebarOpen ? 'gap-3 px-4 justify-start' : 'justify-center px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-3 !px-4 !justify-start' : '!justify-center !px-0'">
            <x-icon name="settings" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">Pengaturan</span>
        </a>
    </nav>

    {{-- Bottom Actions --}}
    <div class="mt-auto space-y-4 {{ $isSidebarOpen ? 'px-4' : 'px-2' }}" :class="(isMobile || sidebarOpen) ? '!px-3 sm:!px-4' : '!px-2'">
        <a href="{{ route('consultations.create') }}" @click="if(window.innerWidth < 1024) sidebarOpen = false" aria-label="Tambah Konsultasi" title="Tambah Konsultasi"
           class="w-full border border-primary/20 bg-primary/12 text-primary py-3 rounded-xl text-xs font-semibold shadow-[0_14px_28px_rgb(var(--color-primary-rgb)/0.14)] hover:bg-primary hover:text-on-primary hover:scale-[0.98] transition-all duration-200 active:scale-95 flex items-center justify-center overflow-hidden {{ $isSidebarOpen ? 'gap-2 px-4' : 'px-0' }}"
           :class="(isMobile || sidebarOpen) ? '!gap-2 !px-4' : '!px-0'">
            <x-icon name="add_circle" class="w-5 h-5 shrink-0" />
            <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="truncate">Tambah Konsultasi</span>
        </a>
        <div class="pt-4 border-t border-surface-container-high/80">
            <form method="POST" action="{{ route('logout') }}">
                @csrf
                <button type="submit" aria-label="Logout" title="Logout" class="flex items-center py-2 text-on-surface-variant hover:text-error transition-colors text-sm font-headline w-full text-left {{ $isSidebarOpen ? 'gap-3 px-3 justify-start' : 'justify-center px-0' }}"
                        :class="(isMobile || sidebarOpen) ? '!gap-3 !px-3 !justify-start' : '!justify-center !px-0'">
                    <x-icon name="logout" class="w-5 h-5 shrink-0" />
                    <span x-show="isMobile || sidebarOpen" x-transition.opacity style="{{ $isSidebarOpen ? '' : 'display: none;' }}" class="whitespace-nowrap">Logout</span>
                </button>
            </form>
        </div>
    </div>
</aside>
