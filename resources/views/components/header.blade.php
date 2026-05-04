@php
    $user = auth()->user();
@endphp

<header class="app-header sticky top-0 w-full z-30 bg-surface-container-lowest/80 border-b border-surface-container-low backdrop-blur-xl flex justify-between items-center gap-2 sm:gap-4 px-3 sm:px-6 lg:px-8 py-2.5 sm:py-4 overflow-x-clip">
    <div class="app-header__left flex min-w-0 flex-1 items-center gap-2 sm:gap-4">
        <button @click="sidebarOpen = !sidebarOpen"
                :aria-expanded="sidebarOpen.toString()"
                aria-controls="sidebar-drawer"
                :aria-label="sidebarOpen ? 'Tutup sidebar' : 'Buka sidebar'"
                class="w-9 h-9 sm:w-10 sm:h-10 rounded-xl flex items-center justify-center hover:bg-surface-container/50 transition-colors text-on-surface-variant shrink-0 relative z-40">
            <x-icon name="menu" class="w-5 h-5" />
        </button>

        @if($user->isAdmin() && $user->account)
            <div class="flex min-w-0 items-center gap-2 sm:gap-3">
                <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-widest mt-0.5 hidden sm:inline">AKUN :</span>
                <div class="app-header__account bg-surface-container-high px-2 sm:px-3 py-1.5 rounded-lg flex items-center gap-2 shadow-sm border border-outline-variant/10 min-w-0">
                    <div class="w-2 h-2 rounded-full bg-primary animate-pulse-soft"></div>
                    <span class="font-bold text-on-surface text-[10px] sm:text-xs tracking-wide leading-tight mt-0.5 whitespace-normal break-words max-w-[9.5rem] sm:max-w-[12rem] md:max-w-none">{{ $user->account->name }}</span>
                </div>
            </div>
        @else
            <div class="app-header__brand-wrap flex min-w-0 items-center gap-2">
                <div class="app-header__brand-icon w-8 h-8 rounded-lg bg-primary/14 border border-primary/10 text-primary flex items-center justify-center shrink-0 sm:hidden">
                    <x-icon name="architecture" class="w-4 h-4" />
                </div>
                <span class="app-header__brand app-header__brand-text text-sm sm:text-lg font-extrabold text-on-surface font-headline leading-none mt-0.5 tracking-tight whitespace-nowrap truncate max-w-[6.5rem] sm:max-w-none">E-REPORT</span>
            </div>
        @endif
    </div>

    <div class="app-header__right flex shrink-0 items-center gap-2 sm:gap-4">
        <div class="app-header__actions flex items-center gap-1.5 sm:gap-4 text-on-surface-variant">
            <button type="button"
                    @click="toggleTheme()"
                    :aria-label="themeMode === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap'"
                    :title="themeMode === 'dark' ? 'Ganti ke light mode' : 'Ganti ke dark mode'"
                    class="theme-toggle-btn header-theme-full hidden sm:inline-flex"
                    :class="{ 'is-dark': themeMode === 'dark' }">
                <span class="theme-toggle-btn__rail">
                    <span class="theme-toggle-btn__thumb"></span>
                </span>
                <span class="theme-toggle-btn__meta">
                    <span class="text-primary">
                        <x-icon name="wb_sunny" x-show="themeMode === 'light'" x-cloak class="w-4 h-4" />
                        <x-icon name="dark_mode" x-show="themeMode === 'dark'" x-cloak class="w-4 h-4" />
                    </span>
                    <span class="theme-toggle-btn__label" x-text="themeMode === 'dark' ? 'Dark' : 'Light'"></span>
                </span>
            </button>

            <button type="button"
                    @click="toggleTheme()"
                    :aria-label="themeMode === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap'"
                    class="header-theme-compact sm:hidden w-9 h-9 rounded-xl bg-surface-container-low border border-surface-container-high text-primary flex items-center justify-center shadow-sm shrink-0">
                <x-icon name="wb_sunny" x-show="themeMode === 'light'" x-cloak class="w-5 h-5" />
                <x-icon name="dark_mode" x-show="themeMode === 'dark'" x-cloak class="w-5 h-5" />
            </button>

            <div x-data="notificationCenter({{ $initialTotalAlerts }}, '{{ route('api.notifications') }}', '{{ route('api.notifications.summary') }}', '{{ csrf_token() }}')"
                 x-init="startPolling()"
                 @click.outside="open = false"
                 class="relative">
                <button type="button"
                        @click="togglePanel()"
                        :aria-expanded="open.toString()"
                        aria-haspopup="dialog"
                        aria-label="Buka notifikasi"
                        class="header-icon-btn relative w-9 h-9 sm:w-auto sm:h-auto rounded-xl sm:rounded-none bg-surface-container-low border border-surface-container-high sm:bg-transparent sm:border-0 flex items-center justify-center opacity-80 hover:text-primary transition-colors shrink-0">
                    <x-icon name="notifications" class="w-5 h-5 sm:w-6 sm:h-6" />
                    <span x-show="badgeCount > 0" x-cloak class="absolute -top-1 -right-1 w-4 h-4 bg-error text-on-error rounded-full text-[9px] font-bold flex items-center justify-center border border-surface-container-lowest"
                          x-text="badgeCount"></span>
                </button>

                <div x-show="open" x-transition.opacity x-cloak class="absolute right-0 mt-3 w-72 sm:w-80 bg-surface-container-lowest rounded-2xl shadow-xl border border-surface-container-low overflow-hidden focus:outline-none">
                    <div class="p-4 bg-surface-container-low/50 border-b border-surface-container">
                        <span class="text-xs font-bold uppercase tracking-widest text-on-surface-variant">Notifikasi & Chat</span>
                    </div>
                    <div class="max-h-96 overflow-y-auto">
                        <div x-show="detailsLoading" x-cloak class="p-6 text-center">
                            <div class="mx-auto h-8 w-8 animate-spin rounded-full border-2 border-primary/20 border-t-primary"></div>
                            <p class="mt-3 text-xs font-medium text-on-surface-variant">Memuat notifikasi...</p>
                        </div>
                        <div x-show="!detailsLoading" x-cloak>
                            <div x-show="detailsError" x-cloak class="px-4 py-3 text-xs font-semibold text-error bg-error/5 border-b border-error/10" x-text="detailsError"></div>

                            <template x-if="unreadNotes.length > 0">
                                <div>
                                    <div class="bg-primary/5 px-4 py-2 border-b border-primary/10">
                                        <span class="text-[10px] font-bold text-primary uppercase tracking-wider">Chat / Catatan Baru</span>
                                    </div>
                                    <template x-for="note in unreadNotes" :key="note.id">
                                        <a :href="note.consultation_url || '#'" class="block p-4 border-b border-surface-container-low hover:bg-primary/[0.02] transition-colors group">
                                            <div class="flex items-start gap-3">
                                                <div class="w-8 h-8 rounded-full bg-primary-container flex items-center justify-center text-primary font-bold text-[10px] shrink-0" x-text="note.author_initial"></div>
                                                <div class="min-w-0">
                                                    <p class="text-xs font-bold text-on-surface truncate"><span x-text="note.author_name"></span><span> mencatat:</span></p>
                                                    <p class="text-xs text-on-surface-variant line-clamp-1 mt-1" x-text="`\"${note.body}\"`"></p>
                                                    <div class="flex items-center gap-2 mt-2">
                                                        <span class="text-[9px] font-bold px-1.5 py-0.5 bg-surface-container rounded text-on-surface-variant" x-text="note.consultation_name"></span>
                                                        <span class="text-[9px] text-outline-variant" x-text="note.created_human"></span>
                                                    </div>
                                                </div>
                                            </div>
                                        </a>
                                    </template>
                                </div>
                                </div>
                            </template>

                            <div class="bg-surface-container-low/30 px-4 py-2 border-b border-surface-container-low">
                                <span class="text-[10px] font-bold text-on-surface-variant uppercase tracking-wider">To-Do / Pengingat</span>
                            </div>
                            <template x-if="activeReminders.length > 0">
                                <div>
                                    <template x-for="reminder in activeReminders" :key="reminder.id">
                                        <div class="p-4 border-b border-surface-container-low hover:bg-surface-container-low/30 transition-colors group">
                                            <a :href="reminder.consultation_url" class="block">
                                                <p class="text-sm font-semibold text-on-surface group-hover:text-primary transition-colors" x-text="reminder.message"></p>
                                                <p class="text-[10px] text-on-surface-variant mt-1 flex items-center gap-1">
                                                    <x-icon name="schedule" class="w-3 h-3" x-bind:class="reminder.overdue ? 'text-error' : ''" />
                                                    <span :class="reminder.overdue ? 'text-error font-bold' : ''">
                                                        <span x-text="reminder.remind_human"></span>
                                                        <span> (</span>
                                                        <span x-text="reminder.remind_label"></span>
                                                        <span>)</span>
                                                    </span>
                                                </p>
                                            </a>
                                            <div class="mt-2 flex justify-between items-center gap-3">
                                                <div class="flex items-center gap-2 min-w-0">
                                                    <span class="text-[10px] font-medium text-outline-variant truncate" x-text="reminder.consultation_name"></span>
                                                    <template x-if="reminder.owner_name">
                                                        <span class="text-[10px] font-bold text-primary bg-primary/10 px-1.5 py-0.5 rounded" x-text="reminder.owner_name"></span>
                                                    </template>
                                                </div>
                                                <button type="button"
                                                        @click.prevent="markReminderRead(reminder)"
                                                        class="text-[10px] bg-primary/10 text-primary px-2 py-1 rounded font-bold hover:bg-primary/20 transition-colors shrink-0">
                                                    Tandai Selesai
                                                </button>
                                            </div>
                                        </div>
                                    </template>
                                </div>
                            </template>
                            <div x-show="!detailsLoading && activeReminders.length === 0" x-cloak class="p-6 text-center">
                                <x-icon name="task" class="w-8 h-8 text-outline-variant/40 mx-auto mb-2" />
                                <p class="text-xs text-on-surface-variant font-medium">Yeay! Tidak ada tugas yang tertunda.</p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <button type="button" aria-label="Bantuan aplikasi" title="Bantuan aplikasi" class="header-help-btn opacity-80 hover:text-primary transition-colors hidden sm:block shrink-0">
                <x-icon name="help_outline" class="w-6 h-6" />
            </button>
        </div>
        <div class="h-6 w-px bg-outline-variant/30 hidden sm:block"></div>
        <div x-data="{ userMenu: false }" @click.away="userMenu = false" class="relative flex items-center gap-2 sm:gap-3 pl-0 sm:pl-2 shrink-0">
            <button type="button"
                    @click="userMenu = !userMenu"
                    :aria-expanded="userMenu.toString()"
                    aria-haspopup="menu"
                    aria-controls="header-user-menu"
                    aria-label="Buka menu pengguna"
                    class="header-user-trigger flex items-center gap-2 sm:gap-3 hover:opacity-80 transition-all focus:outline-none">
                <div class="text-right hidden sm:flex flex-col justify-center">
                    <p class="text-xs font-bold text-on-surface leading-tight">{{ $user->name }}</p>
                    <p class="text-[9px] text-on-surface-variant uppercase tracking-widest leading-tight">{{ $user->isSuperAdmin() ? 'Super Admin' : 'Admin Akun' }}</p>
                </div>
                <div class="header-user-avatar w-[2.125rem] h-[2.125rem] sm:w-10 sm:h-10 rounded-xl bg-primary-container flex items-center justify-center text-primary font-bold text-xs sm:text-sm ring-2 ring-surface-container-highest shadow-sm">
                    {{ strtoupper(substr($user->name, 0, 2)) }}
                </div>
            </button>

            <div x-show="userMenu"
                 x-transition:enter="transition ease-out duration-200"
                 x-transition:enter-start="opacity-0 translate-y-2"
                 x-transition:enter-end="opacity-100 translate-y-0"
                 x-transition:leave="transition ease-in duration-150"
                 x-transition:leave-start="opacity-100 translate-y-0"
                 x-transition:leave-end="opacity-0 translate-y-2"
                 x-cloak
                 id="header-user-menu"
                 class="absolute right-0 top-full mt-3 w-56 bg-surface-container-lowest rounded-2xl shadow-xl border border-surface-container-low overflow-hidden z-50">
                <div class="p-4 bg-surface-container-low/50 border-b border-surface-container sm:hidden">
                    <p class="text-xs font-bold text-on-surface">{{ $user->name }}</p>
                    <p class="text-[9px] text-on-surface-variant uppercase tracking-wider mt-0.5">{{ $user->isSuperAdmin() ? 'Super Admin' : 'Admin Akun' }}</p>
                </div>

                <div class="py-2">
                    <a href="{{ route('settings') }}" class="flex items-center gap-3 px-4 py-2.5 text-xs font-bold text-on-surface-variant hover:bg-surface-container-low hover:text-primary transition-colors">
                        <x-icon name="settings" class="w-[18px] h-[18px]" />
                        <span>Pengaturan Akun</span>
                    </a>

                    <div class="my-2 border-t border-surface-container-low"></div>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf
                        <button type="submit" class="w-full flex items-center gap-3 px-4 py-2.5 text-xs font-bold text-error hover:bg-error/5 transition-colors">
                            <x-icon name="logout" class="w-[18px] h-[18px]" />
                            <span>Keluar Sistem</span>
                        </button>
                    </form>
                </div>
            </div>
        </div>
    </div>
</header>
