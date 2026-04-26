@extends('layouts.guest')

@section('content')
{{-- Main wrapper. On mobile, the background image covers the screen. On desktop, it's a solid dark color. --}}
<div class="login-shell relative font-sans">
    <div class="login-golden-horizon fixed inset-0 z-0"></div>
    {{-- Dark Dotted Grid Background --}}
    <div class="login-dotted-grid fixed inset-0 z-0"></div>

    {{-- Mobile Background Image (Only visible < lg) --}}
    <div class="login-mobile-background fixed inset-0 z-0 lg:hidden"></div>
    <div class="login-mobile-background fixed inset-0 z-0 lg:hidden bg-cover bg-center bg-no-repeat opacity-20 blur-[2px]" style="background-image: url('{{ asset('images/bg-login.png') }}');"></div>
    <div class="login-mobile-overlay fixed inset-0 z-0 lg:hidden"></div>

    <div class="login-stage relative z-10 mx-auto flex flex-col h-full min-h-screen w-full max-w-[1360px] items-center justify-center px-4 sm:px-6 lg:px-8 py-10 lg:py-12 2xl:py-16">
        
        {{-- Mobile & Tablet Header (< xl) --}}
        <div class="w-full flex items-center justify-between mb-8 xl:hidden animate-fade-in">
            <img src="{{ asset('images/putra_corporation_exact.svg') }}" 
                 alt="Putra Corporation Logo" 
                 class="login-brand-logo h-[90px] sm:h-[112px] md:h-[132px] w-auto object-contain" />
                 
            <button type="button"
                    @click="toggleTheme()"
                    :aria-label="themeMode === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap'"
                    class="mt-2 sm:mt-3 w-10 h-10 sm:w-11 sm:h-11 flex items-center justify-center rounded-full bg-surface-container hover:bg-surface-container-highest border border-outline-variant/30 text-on-surface-variant hover:text-on-surface transition-all duration-300 shadow-sm focus:outline-none focus:ring-2 focus:ring-primary/30 group">
                <x-icon name="wb_sunny" x-show="themeMode === 'light'" x-cloak class="w-5 h-5 group-hover:rotate-45 transition-transform duration-500" />
                <x-icon name="dark_mode" x-show="themeMode === 'dark'" x-cloak class="w-5 h-5 group-hover:-rotate-12 transition-transform duration-500" />
            </button>
        </div>

        <div x-data="loginPage({ waNumber: '6285168112098', autoSlideMs: 4600 })"
             x-init="startSlider()"
             class="grid w-full items-stretch gap-6 lg:gap-8 xl:gap-10 xl:grid-cols-12">
            
            {{-- Unified Left Side: Branding + Slider (Desktop Only) --}}
            <div class="hidden xl:flex flex-col xl:col-span-7 2xl:col-span-7 h-full w-full">
                {{-- Rounded Background Banner Slider Container --}}
                <div class="login-hero-panel relative flex-1 w-full min-h-[640px] rounded-[2rem] overflow-hidden shadow-2xl border border-outline-variant/10 flex flex-col"
                     @mouseenter="pauseSlider()"
                     @mouseleave="startSlider()">
                    <div class="absolute inset-0 bg-surface"></div>
                    {{-- Background pattern with reduced opacity (6%) for subtlety --}}
                    <div class="absolute inset-0 bg-cover bg-center bg-no-repeat opacity-[0.06] transition-all duration-1000" style="background-image: url('{{ asset('images/bg-login.png') }}'); background-size: cover; background-position: center;"></div>
                    {{-- Inner subtle gradient for text readability and elegance --}}
                    <div class="login-banner-overlay absolute inset-0"></div>
                    {{-- Soft radial glow (gold accent) --}}
                    <div class="login-hero-glow absolute -left-16 top-1/3"></div>
                    <div class="login-hero-glow login-hero-glow--secondary absolute -right-10 bottom-20"></div>
                    {{-- Subtle geometric rings --}}
                    <div class="login-hero-rings absolute inset-x-0 top-14 flex justify-center pointer-events-none">
                        <div class="login-hero-ring login-hero-ring--outer"></div>
                        <div class="login-hero-ring login-hero-ring--inner"></div>
                    </div>
                    
                    {{-- Unified Header (Logo + Toggle) INSIDE the slider panel --}}
                    <div class="relative z-50 flex items-center justify-between px-8 pt-3 w-full animate-fade-in">
                        <img src="{{ asset('images/putra_corporation_exact.svg') }}" 
                             alt="Putra Corporation Logo" 
                             class="login-brand-logo h-[102px] 2xl:h-[122px] w-auto object-contain transition-transform hover:scale-[1.02] duration-300" />
                             
                        <button type="button"
                                @click="toggleTheme()"
                                :aria-label="themeMode === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap'"
                                :title="themeMode === 'dark' ? 'Ganti ke light mode' : 'Ganti ke dark mode'"
                                class="login-hero-theme-toggle w-12 h-12 flex items-center justify-center rounded-[1.35rem] border backdrop-blur-xl transition-all duration-300 shadow-lg focus:outline-none focus:ring-2 focus:ring-primary/30 group">
                            <span class="login-hero-theme-toggle__glow" aria-hidden="true"></span>
                            <x-icon name="wb_sunny" x-show="themeMode === 'light'" x-cloak class="relative z-[1] w-5 h-5 group-hover:rotate-45 transition-transform duration-500" />
                            <x-icon name="dark_mode" x-show="themeMode === 'dark'" x-cloak class="relative z-[1] w-5 h-5 group-hover:-rotate-12 transition-transform duration-500" />
                        </button>
                    </div>

                    {{-- Slide Content Area --}}
                    <div class="relative z-10 flex-1 h-full w-full">
                        {{-- ═══════════════════════════════════════════════════════ --}}
                        {{-- SLIDE 1: CRM Interior — Phone LEFT, Copy RIGHT --}}
                        {{-- ═══════════════════════════════════════════════════════ --}}
                        <article x-show="activeSlide === 0"
                                 x-transition:enter="transition ease-out duration-700"
                                 x-transition:enter-start="opacity-0 translate-y-6 scale-[0.98]"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-400"
                                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave-end="opacity-0 -translate-y-3 scale-[0.98]"
                                 class="login-hero-slide login-hero-slide--reversed absolute inset-0">
                            
                            {{-- Phone Mockup (Left Side) --}}
                            <div class="login-daisy-phone-scene login-daisy-phone-scene--left">
                                <div class="login-daisy-phone-stage"></div>
                                <div class="mockup-phone login-daisy-phone border-primary/70">
                                    <div class="mockup-phone-camera"></div>
                                    <div class="mockup-phone-display login-daisy-phone-display">
                                        <div class="login-phone-ui">
                                            <div class="login-phone-ui__top">
                                                <div class="flex items-center gap-2">
                                                    <div class="flex h-8 w-8 items-center justify-center rounded-xl bg-primary/18 text-primary">
                                                        <x-icon name="architecture" class="h-4 w-4" />
                                                    </div>
                                                    <div>
                                                        <div class="text-[0.82rem] font-bold text-white">Dashboard CRM</div>
                                                        <div class="text-[0.62rem] text-white/50">Interior Design</div>
                                                    </div>
                                                </div>
                                                <span class="rounded-full bg-primary/20 px-2.5 py-1 text-[0.6rem] font-bold text-primary-fixed">Realtime</span>
                                            </div>

                                            <div class="login-phone-ui__card login-phone-ui__card--hero">
                                                <div class="text-[0.72rem] font-semibold text-white/70">Lead Bulan Ini</div>
                                                <div class="mt-1 text-[2.3rem] font-extrabold leading-none text-white font-headline">897</div>
                                                <div class="mt-2 text-[0.72rem] font-medium text-primary-fixed">+18% dari bulan lalu</div>
                                                <div class="login-phone-ui__chart">
                                                    <span style="--point: 24%"></span>
                                                    <span style="--point: 28%"></span>
                                                    <span style="--point: 36%"></span>
                                                    <span style="--point: 33%"></span>
                                                    <span style="--point: 46%"></span>
                                                    <span style="--point: 42%"></span>
                                                    <span style="--point: 55%"></span>
                                                    <span style="--point: 49%"></span>
                                                    <span style="--point: 66%"></span>
                                                    <span style="--point: 78%"></span>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-2 gap-3">
                                                <div class="login-phone-ui__card">
                                                    <div class="text-[0.68rem] text-white/65">Follow Up</div>
                                                    <div class="mt-1 text-[1.8rem] font-extrabold text-white font-headline">245</div>
                                                    <div class="mt-1 text-[0.68rem] text-white/45">Perlu ditindaklanjuti</div>
                                                </div>
                                                <div class="login-phone-ui__card">
                                                    <div class="text-[0.68rem] text-white/65">Konversi</div>
                                                    <div class="mt-1 text-[1.8rem] font-extrabold text-white font-headline">42%</div>
                                                    <div class="mt-1 text-[0.68rem] text-white/45">Dari total lead</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Floating Glassmorphism Widget --}}
                                <div class="login-floating-widget login-floating-widget--top-right">
                                    <div class="text-[0.62rem] font-semibold text-white/60 uppercase tracking-wider">Konversi Hari Ini</div>
                                    <div class="text-[1.4rem] font-extrabold text-white font-headline leading-none mt-1">+24%</div>
                                    <div class="text-[0.58rem] text-primary-fixed font-medium mt-0.5">↑ dari kemarin</div>
                                </div>
                            </div>

                            {{-- Copy (Right Side) --}}
                            <div class="login-hero-copy login-hero-copy--right">
                                <span class="login-hero-kicker">CRM Interior</span>
                                <h2 class="login-hero-title">Lead interior tertata, tim lebih sigap.</h2>
                                <p class="login-hero-description">Semua percakapan, status follow up, dan progres akun dirangkum dalam satu dashboard yang cepat dibaca.</p>
                            </div>
                        </article>

                        {{-- ═══════════════════════════════════════════════════════ --}}
                        {{-- SLIDE 2: Kontrol Tim — Phone LEFT, Copy RIGHT --}}
                        {{-- ═══════════════════════════════════════════════════════ --}}
                        <article x-show="activeSlide === 1"
                                 x-transition:enter="transition ease-out duration-700"
                                 x-transition:enter-start="opacity-0 translate-y-6 scale-[0.98]"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-400"
                                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave-end="opacity-0 -translate-y-3 scale-[0.98]"
                                 class="login-hero-slide login-hero-slide--reversed absolute inset-0">
                            
                            {{-- Phone Mockup (Left Side) --}}
                            <div class="login-daisy-phone-scene login-daisy-phone-scene--left login-daisy-phone-scene--alt">
                                <div class="login-daisy-phone-stage"></div>
                                <div class="mockup-phone login-daisy-phone border-primary/60">
                                    <div class="mockup-phone-camera"></div>
                                    <div class="mockup-phone-display login-daisy-phone-display">
                                        <div class="login-phone-ui login-phone-ui--activity">
                                            <div class="login-phone-ui__top">
                                                <div>
                                                    <div class="text-[0.82rem] font-bold text-white">Aktivitas Admin</div>
                                                    <div class="text-[0.62rem] text-white/50">Pantauan harian</div>
                                                </div>
                                                <span class="rounded-full bg-sky-400/15 px-2.5 py-1 text-[0.6rem] font-bold text-sky-200">Online</span>
                                            </div>

                                            <div class="login-phone-ui__timeline-list">
                                                <div class="login-phone-ui__timeline-item">
                                                    <span class="login-phone-ui__timeline-dot"></span>
                                                    <div>
                                                        <div class="login-phone-ui__timeline-name">Steven</div>
                                                        <div class="login-phone-ui__timeline-note">Upload laporan harian</div>
                                                    </div>
                                                </div>
                                                <div class="login-phone-ui__timeline-item">
                                                    <span class="login-phone-ui__timeline-dot bg-sky-400 shadow-[0_0_0_4px_rgba(56,189,248,0.12)]"></span>
                                                    <div>
                                                        <div class="login-phone-ui__timeline-name">Gibran Interior</div>
                                                        <div class="login-phone-ui__timeline-note">Lead baru masuk</div>
                                                    </div>
                                                </div>
                                                <div class="login-phone-ui__timeline-item">
                                                    <span class="login-phone-ui__timeline-dot bg-emerald-400 shadow-[0_0_0_4px_rgba(52,211,153,0.12)]"></span>
                                                    <div>
                                                        <div class="login-phone-ui__timeline-name">Niscala Furniture</div>
                                                        <div class="login-phone-ui__timeline-note">Follow up dijadwalkan</div>
                                                    </div>
                                                </div>
                                            </div>

                                            <div class="grid grid-cols-2 gap-3 login-phone-ui__stats-grid">
                                                <div class="login-phone-ui__card">
                                                    <div class="text-[0.68rem] text-white/65">Response</div>
                                                    <div class="mt-1 text-[1.65rem] font-extrabold text-white font-headline">91%</div>
                                                </div>
                                                <div class="login-phone-ui__card">
                                                    <div class="text-[0.68rem] text-white/65">Laporan</div>
                                                    <div class="mt-1 text-[1.65rem] font-extrabold text-white font-headline">128</div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Floating Glassmorphism Widget --}}
                                <div class="login-floating-widget login-floating-widget--bottom-right">
                                    <div class="flex items-center gap-2">
                                        <div class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></div>
                                        <div class="text-[0.62rem] font-bold text-emerald-300 uppercase tracking-wider">3 Admin Online</div>
                                    </div>
                                    <div class="text-[0.58rem] text-white/50 mt-0.5">Terakhir aktif 2 menit lalu</div>
                                </div>
                            </div>

                            {{-- Copy (Right Side) --}}
                            <div class="login-hero-copy login-hero-copy--right">
                                <span class="login-hero-kicker">Kontrol Tim</span>
                                <h2 class="login-hero-title">Pantau ritme admin tanpa kehilangan detail.</h2>
                                <p class="login-hero-description">Lihat akun aktif, respons tim, dan progres target konversi dalam panel yang rapi untuk dipakai harian.</p>
                            </div>
                        </article>

                        {{-- ═══════════════════════════════════════════════════════ --}}
                        {{-- SLIDE 3: Laporan Cerdas — Phone LEFT, Copy RIGHT --}}
                        {{-- ═══════════════════════════════════════════════════════ --}}
                        <article x-show="activeSlide === 2"
                                 x-transition:enter="transition ease-out duration-700"
                                 x-transition:enter-start="opacity-0 translate-y-6 scale-[0.98]"
                                 x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave="transition ease-in duration-400"
                                 x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                                 x-transition:leave-end="opacity-0 -translate-y-3 scale-[0.98]"
                                 class="login-hero-slide login-hero-slide--reversed absolute inset-0">
                            
                            {{-- Phone Mockup (Left Side) --}}
                            <div class="login-daisy-phone-scene login-daisy-phone-scene--left login-daisy-phone-scene--focus">
                                <div class="login-daisy-phone-stage"></div>
                                <div class="mockup-phone login-daisy-phone border-primary/70">
                                    <div class="mockup-phone-camera"></div>
                                    <div class="mockup-phone-display login-daisy-phone-display">
                                        <div class="login-phone-ui">
                                            <div class="login-phone-ui__top">
                                                <div>
                                                    <div class="text-[0.82rem] font-bold text-white">Distribusi Status</div>
                                                    <div class="text-[0.62rem] text-white/50">Insight realtime</div>
                                                </div>
                                                <span class="rounded-full bg-primary/20 px-2.5 py-1 text-[0.6rem] font-bold text-primary-fixed">Live</span>
                                            </div>

                                            <div class="login-phone-ui__card login-phone-ui__card--distribution">
                                                <div class="login-phone-ui__donut">
                                                    <div class="login-phone-ui__donut-core">
                                                        <strong>64%</strong>
                                                        <span>Progress</span>
                                                    </div>
                                                </div>
                                                <div class="login-phone-ui__legend-list text-[0.68rem] text-white/70">
                                                    <div class="login-phone-ui__legend-row"><span class="h-2.5 w-2.5 rounded-full bg-primary"></span><span>Prospek</span><strong>136</strong></div>
                                                    <div class="login-phone-ui__legend-row"><span class="h-2.5 w-2.5 rounded-full bg-sky-400"></span><span>Follow Up</span><strong>122</strong></div>
                                                    <div class="login-phone-ui__legend-row"><span class="h-2.5 w-2.5 rounded-full bg-amber-100"></span><span>Negosiasi</span><strong>68</strong></div>
                                                    <div class="login-phone-ui__legend-row"><span class="h-2.5 w-2.5 rounded-full bg-white/35"></span><span>Closing</span><strong>34</strong></div>
                                                </div>
                                            </div>

                                            <div class="login-phone-ui__card">
                                                <div class="mb-3 text-[0.72rem] font-semibold text-white/65">Aktivitas Terbaru</div>
                                                <div class="space-y-2.5 text-[0.66rem]">
                                                    <div class="login-phone-ui__activity-row text-white/80"><span>Putra Interior</span><span class="text-white/45">1 jam</span></div>
                                                    <div class="login-phone-ui__activity-row text-white/80"><span>Niscala Furniture</span><span class="text-white/45">15 min</span></div>
                                                    <div class="login-phone-ui__activity-row text-white/80"><span>Gibran Interior</span><span class="text-white/45">baru</span></div>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                {{-- Floating Glassmorphism Donut Widget --}}
                                <div class="login-floating-widget login-floating-widget--top-right login-floating-widget--donut">
                                    <div class="login-floating-donut">
                                        <div class="login-floating-donut__core">
                                            <strong class="text-[0.85rem] font-extrabold text-white leading-none">64%</strong>
                                        </div>
                                    </div>
                                    <div class="text-[0.58rem] text-white/65 font-semibold mt-1.5 text-center">Progress</div>
                                </div>
                            </div>

                            {{-- Copy (Right Side) --}}
                            <div class="login-hero-copy login-hero-copy--right">
                                <span class="login-hero-kicker">Laporan Cerdas</span>
                                <h2 class="login-hero-title">Ambil keputusan lebih cepat dari dashboard.</h2>
                                <p class="login-hero-description">Panel dirancang fokus pada angka penting, jadi tim tidak tenggelam di data yang berulang.</p>
                            </div>
                        </article>
                    </div>

                    {{-- Bottom Navigation Bar — Centered --}}
                    <div class="absolute bottom-6 left-0 right-0 z-20 flex items-center justify-center">
                        <div class="login-hero-dots">
                            <button type="button" class="login-hero-dot" :class="{ 'is-active': activeSlide === 0 }" @click="setSlide(0)" aria-label="Slide 1"></button>
                            <button type="button" class="login-hero-dot" :class="{ 'is-active': activeSlide === 1 }" @click="setSlide(1)" aria-label="Slide 2"></button>
                            <button type="button" class="login-hero-dot" :class="{ 'is-active': activeSlide === 2 }" @click="setSlide(2)" aria-label="Slide 3"></button>
                        </div>
                    </div>
                </div>
            </div>

            {{-- Right Side: Login Form --}}
            <div class="login-form-column flex w-full items-center justify-center xl:justify-end xl:col-span-5 relative z-20 pt-2 sm:pt-4 xl:pt-0 mt-1 sm:mt-4 xl:mt-0">
                <div class="login-panel relative w-full max-w-[420px] xl:max-w-[430px] 2xl:max-w-[440px] animate-fade-in rounded-[1.75rem] p-5 sm:p-6 lg:px-7 lg:py-6 bg-surface-container/60 sm:bg-surface-container/40 border border-outline-variant/30 backdrop-blur-xl shadow-2xl">
                    
                    {{-- Mobile Logo (Only visible < xl if they want it) -> We removed it because we added it directly on top of screen --}}

                    {{-- Header --}}
                    <div class="mb-6 sm:mb-7 text-center xl:text-left">
                        <h1 class="text-[1.95rem] sm:text-[2.1rem] lg:text-[2.2rem] font-extrabold leading-[1.05] mb-2 tracking-[-0.03em] text-on-surface">Selamat Datang</h1>
                        <p class="text-on-surface-variant text-[14px] sm:text-[15px] font-medium leading-relaxed max-w-[360px] mx-auto xl:mx-0">Kelola laporan, prospek, dan aktivitas tim dari satu tempat yang terintegrasi.</p>
                    </div>

                    @if($errors->any())
                    <div class="bg-error/10 border border-error/20 text-error px-4 py-3.5 rounded-xl text-sm font-medium flex items-start gap-3 mb-5 sm:mb-6 shadow-inner animate-fade-in">
                        <x-icon name="error" class="w-5 h-5 shrink-0" />
                        <span>Kredensial tidak cocok. Silakan periksa kembali email & sandi Anda.</span>
                    </div>
                    @endif

                    <form method="POST" action="{{ route('login.authenticate') }}" class="space-y-4 sm:space-y-5" x-data="{ loading: false }" @submit="loading = true">
                        @csrf

                        {{-- Email Input --}}
                        <div class="space-y-2">
                            <label for="email" class="text-[13px] font-bold text-on-surface-variant ml-1 uppercase tracking-wider">Email</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <x-icon name="mail" class="w-[18px] h-[18px] text-on-surface-variant group-focus-within:text-primary transition-colors duration-300" />
                                </div>
                                <input type="email" id="email" name="email" value="{{ old('email') }}"
                                       class="w-full min-h-[56px] rounded-xl pl-[42px] pr-4 py-3.5 bg-surface-container-highest border border-outline-variant/40 text-[15px] text-on-surface placeholder:text-on-surface-variant/50 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all duration-300 hover:border-outline-variant"
                                       placeholder="nama@perusahaan.com" autocomplete="email" required autofocus />
                            </div>
                        </div>

                        {{-- Password Input --}}
                        <div class="space-y-2" x-data="{ show: false }">
                            <label for="password" class="text-[13px] font-bold text-on-surface-variant ml-1 uppercase tracking-wider">Kata Sandi</label>
                            <div class="relative group">
                                <div class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none">
                                    <x-icon name="lock" class="w-[18px] h-[18px] text-on-surface-variant group-focus-within:text-primary transition-colors duration-300" />
                                </div>
                                <input :type="show ? 'text' : 'password'" id="password" name="password"
                                       class="w-full min-h-[56px] rounded-xl pl-[42px] pr-12 py-3.5 bg-surface-container-highest border border-outline-variant/40 text-[15px] text-on-surface placeholder:text-on-surface-variant/50 focus:border-primary focus:ring-2 focus:ring-primary/20 outline-none transition-all duration-300 hover:border-outline-variant"
                                       placeholder="••••••••" autocomplete="current-password" required />
                                <button type="button" @click="show = !show" class="absolute inset-y-0 right-0 pr-4 flex items-center text-on-surface-variant hover:text-on-surface transition-colors focus:outline-none" aria-label="Toggle password visibility">
                                    <x-icon name="visibility" class="w-5 h-5" x-show="!show" x-cloak />
                                    <x-icon name="visibility_off" class="w-5 h-5" x-show="show" x-cloak />
                                </button>
                            </div>
                        </div>

                        {{-- Actions --}}
                        <div class="flex flex-col gap-3 pt-1 pb-1.5 sm:flex-row sm:items-center sm:justify-between">
                            <label class="flex items-center gap-2.5 cursor-pointer group/chk">
                                <div class="relative flex items-center justify-center w-[18px] h-[18px] rounded-[5px] border border-outline-variant/60 bg-surface-container-highest group-hover/chk:border-primary transition-colors">
                                    <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }} class="absolute w-full h-full opacity-0 cursor-pointer peer">
                                    <x-icon name="check" class="w-3.5 h-3.5 text-primary opacity-0 peer-checked:opacity-100 transition-opacity" />
                                </div>
                                <span class="text-[14px] font-medium text-on-surface-variant group-hover/chk:text-on-surface transition-colors">Ingat saya</span>
                            </label>

                            <button type="button"
                                    @click="showForgotPasswordModal = true"
                                    class="text-left sm:text-right text-[14px] font-semibold text-primary hover:text-primary-dim transition-colors focus:outline-none focus:underline underline-offset-2">
                                Lupa Password?
                            </button>
                        </div>

                        <button type="submit"
                                :disabled="loading"
                                class="w-full min-h-[58px] bg-primary hover:bg-primary-dim text-on-primary py-4 rounded-xl font-bold text-[15px] tracking-wide shadow-[0_4px_12px_rgba(255,160,0,0.25)] hover:shadow-[0_6px_20px_rgba(255,160,0,0.4)] hover:-translate-y-[1px] transition-all duration-300 focus:outline-none focus:ring-4 focus:ring-primary/30 disabled:opacity-75 disabled:cursor-not-allowed flex items-center justify-center gap-2.5">
                            <span x-show="!loading">Masuk Sekarang</span>
                            <span x-show="loading" x-cloak class="flex items-center gap-2.5">
                                <svg class="animate-spin h-5 w-5 text-on-primary" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24"><circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle><path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path></svg>
                                Sedang memproses...
                            </span>
                        </button>

                    </form>

                    {{-- Support Separator --}}
                    <div class="mt-8 sm:mt-9 flex items-center justify-center gap-4">
                        <div class="h-px bg-gradient-to-r from-transparent via-outline-variant/60 to-transparent flex-1"></div>
                        <span class="text-[11px] font-bold text-on-surface-variant uppercase tracking-[0.2em] whitespace-nowrap">Bantuan Aplikasi</span>
                        <div class="h-px bg-gradient-to-l from-transparent via-outline-variant/60 to-transparent flex-1"></div>
                    </div>

                    <div class="mt-5 sm:mt-6 text-center">
                        <button type="button" @click="showBugModal = true" class="inline-flex items-center justify-center gap-2 w-full min-h-[56px] bg-surface-container-high hover:bg-surface-container-highest text-on-surface-variant hover:text-on-surface border border-outline-variant/40 rounded-xl px-5 py-3.5 text-[14px] font-semibold transition-all duration-300 focus:outline-none focus:ring-2 focus:ring-outline/50 hover:shadow-sm">
                            <x-icon name="bug_report" class="w-5 h-5 opacity-80" />
                            Hubungi Developer (Lapor Bug)
                        </button>
                    </div>

                    {{-- Bug Report Modal --}}
                    <template x-teleport="body">
                        <div x-show="showBugModal" x-cloak class="login-modal-backdrop fixed inset-0 z-[100] flex items-center justify-center p-4"
                             x-transition.opacity.duration.400ms>
                            <div @click.away="showBugModal = false" class="login-modal modal-surface-glow w-full max-w-md rounded-[2rem] shadow-2xl animate-fade-in overflow-hidden mx-2">
                                <div class="bg-gradient-to-r from-error/10 to-transparent px-6 sm:px-8 py-5 sm:py-6 flex items-center justify-between border-b border-error/10">
                                    <div class="flex items-center gap-3 text-error">
                                        <div class="w-8 h-8 rounded-full bg-error/10 flex items-center justify-center">
                                            <x-icon name="warning" class="w-4 h-4" />
                                        </div>
                                        <h3 class="font-bold text-base sm:text-lg font-headline tracking-tight">Lapor Kendala</h3>
                                    </div>
                                    <button @click="showBugModal = false" class="text-on-surface-variant hover:text-on-surface bg-surface-container-low hover:bg-surface-container-high rounded-full p-2 transition-all">
                                        <x-icon name="close" class="w-4 h-4" />
                                    </button>
                                </div>
                                
                                <div class="p-6 sm:p-8">
                                    <p class="text-[14px] text-on-surface-variant mb-6 leading-relaxed">Punya laporan mengenai sistem yang error atau tidak berjalan semestinya? Jelaskan secara detail di bawah ini agar tim teknis kami bisa segera mengatasinya.</p>
                                    
                                    <div class="space-y-6">
                                        <textarea x-model="bugMessage" rows="5" 
                                                  @input="bugError = ''"
                                                  class="w-full rounded-xl px-5 py-4 bg-surface-container-highest border border-outline-variant/40 text-[14px] text-on-surface resize-none outline-none transition-all duration-300 focus:border-primary focus:ring-2 focus:ring-primary/20 hover:border-outline-variant"
                                                  :class="bugError ? 'border-error/80 focus:border-error focus:ring-error/20 ring-2 ring-error/20' : ''"
                                                  placeholder="Contoh: Saya tidak bisa login, muncul pesan galat..."></textarea>
                                        <div x-show="bugError" x-cloak
                                             x-transition:enter="transition ease-out duration-200"
                                             x-transition:enter-start="opacity-0 -translate-y-1"
                                             x-transition:enter-end="opacity-100 translate-y-0"
                                             class="flex items-center gap-2 bg-error/10 text-error px-4 py-2.5 rounded-xl text-xs font-semibold -mt-2">
                                            <x-icon name="error" class="w-4 h-4 shrink-0" />
                                            <span x-text="bugError"></span>
                                        </div>
                                                  
                                        <button type="button" 
                                                @click="submitBugReport()"
                                                class="w-full bg-[#25D366] hover:bg-[#20bd5a] text-white py-4 rounded-xl font-semibold flex items-center justify-center gap-2.5 shadow-[0_4px_12px_rgba(37,211,102,0.25)] hover:shadow-[0_6px_20px_rgba(37,211,102,0.4)] transition-all duration-300 hover:-translate-y-[1px] focus:outline-none focus:ring-4 focus:ring-[#25D366]/30 text-base">
                                            <x-icon name="chat" class="w-[20px] h-[20px]" />
                                            <span>Kirim Laporan via WhatsApp</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>

                    {{-- Forgot Password Modal --}}
                    <template x-teleport="body">
                        <div x-show="showForgotPasswordModal" x-cloak class="login-modal-backdrop login-modal-backdrop--forgot fixed inset-0 z-[100] flex items-center justify-center p-4"
                             x-transition.opacity.duration.400ms>
                            <div @click.away="showForgotPasswordModal = false" class="login-modal login-modal--forgot modal-surface-glow w-full max-w-md rounded-[2rem] shadow-2xl animate-fade-in overflow-hidden mx-2">
                                <div class="login-modal--forgot__header bg-gradient-to-r from-primary/12 to-transparent px-6 sm:px-8 py-5 sm:py-6 flex items-center justify-between border-b border-primary/10">
                                    <div class="login-modal--forgot__title-wrap flex items-center gap-3 text-primary">
                                        <div class="login-modal--forgot__icon w-8 h-8 rounded-full bg-primary/10 flex items-center justify-center">
                                            <x-icon name="lock_reset" class="w-4 h-4" />
                                        </div>
                                        <h3 class="login-modal--forgot__title font-bold text-base sm:text-lg font-headline tracking-tight">Permintaan Reset Password</h3>
                                    </div>
                                    <button @click="showForgotPasswordModal = false" class="login-modal--forgot__close text-on-surface-variant hover:text-on-surface bg-surface-container-low hover:bg-surface-container-high rounded-full p-2 transition-all">
                                        <x-icon name="close" class="w-4 h-4" />
                                    </button>
                                </div>

                                <div class="login-modal--forgot__body p-6 sm:p-8">
                                    <p class="login-modal--forgot__description text-[14px] text-on-surface-variant mb-6 leading-relaxed">Isi data admin dan akun yang Anda pegang. Permintaan akan langsung dikirim ke WhatsApp developer untuk bantuan reset password.</p>

                                    <div class="login-modal--forgot__form space-y-5">
                                        <div class="space-y-1.5">
                                            <label for="forgot-admin-name" class="login-modal--forgot__label text-[13px] font-bold text-on-surface-variant ml-1 uppercase tracking-wider">Nama Admin</label>
                                            <input id="forgot-admin-name"
                                                   type="text"
                                                   x-model="forgotPasswordAdminName"
                                                   @input="forgotPasswordError = ''"
                                                   class="login-modal--forgot__input w-full rounded-xl px-5 py-4 bg-surface-container-highest border border-outline-variant/40 text-[14px] text-on-surface outline-none transition-all duration-300 focus:border-primary focus:ring-2 focus:ring-primary/20 hover:border-outline-variant"
                                                   placeholder="Contoh: Steven"
                                            />
                                        </div>

                                        <div class="space-y-1.5">
                                            <label for="forgot-account-name" class="login-modal--forgot__label text-[13px] font-bold text-on-surface-variant ml-1 uppercase tracking-wider">Nama Akun Yang Dipegang</label>
                                            <input id="forgot-account-name"
                                                   type="text"
                                                   x-model="forgotPasswordAccountName"
                                                   @input="forgotPasswordError = ''"
                                                   class="login-modal--forgot__input w-full rounded-xl px-5 py-4 bg-surface-container-highest border border-outline-variant/40 text-[14px] text-on-surface outline-none transition-all duration-300 focus:border-primary focus:ring-2 focus:ring-primary/20 hover:border-outline-variant"
                                                   placeholder="Contoh: Putra Interior"
                                            />
                                        </div>

                                        <div x-show="forgotPasswordError" x-cloak
                                             x-transition:enter="transition ease-out duration-200"
                                             x-transition:enter-start="opacity-0 -translate-y-1"
                                             x-transition:enter-end="opacity-100 translate-y-0"
                                             class="flex items-center gap-2 bg-error/10 text-error px-4 py-2.5 rounded-xl text-xs font-semibold">
                                            <x-icon name="error" class="w-4 h-4 shrink-0" />
                                            <span x-text="forgotPasswordError"></span>
                                        </div>

                                        <button type="button"
                                                @click="submitForgotPasswordRequest()"
                                                class="login-modal--forgot__submit w-full bg-[#25D366] hover:bg-[#20bd5a] text-white py-4 rounded-xl font-semibold flex items-center justify-center gap-2.5 shadow-[0_4px_12px_rgba(37,211,102,0.25)] hover:shadow-[0_6px_20px_rgba(37,211,102,0.4)] transition-all duration-300 hover:-translate-y-[1px] focus:outline-none focus:ring-4 focus:ring-[#25D366]/30 text-base">
                                            <x-icon name="chat" class="w-[20px] h-[20px]" />
                                            <span>Kirim Permintaan via WhatsApp</span>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </template>
                </div>
            </div>
        </div>
    </div>
</div>
@endsection
