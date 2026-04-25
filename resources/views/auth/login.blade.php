@extends('layouts.guest')

@section('content')
{{-- Main wrapper. On mobile, the background image covers the screen. On desktop, it's a solid dark color. --}}
<div class="login-shell relative font-sans">
    {{-- Mobile Background Image (Only visible < lg) --}}
    <div class="fixed inset-0 z-0 lg:hidden bg-cover bg-center bg-no-repeat" style="background-image: url('{{ asset('images/bg-login.png') }}');"></div>
    <div class="login-mobile-overlay fixed inset-0 z-0 lg:hidden"></div>

    <div class="login-stage relative z-10 mx-auto flex flex-col h-full w-full max-w-[1320px] items-center justify-center px-3 sm:px-5 lg:px-6 py-6 lg:py-10">
        {{-- Custom Top-Left Logo --}}
        <div class="fixed -top-6 sm:-top-7 lg:-top-10 left-4 sm:left-6 flex items-start animate-fade-in z-[60]">
            <img src="{{ asset('images/putra_corporation_exact.svg') }}" 
                 alt="Putra Corporation Logo" 
                 class="h-[105px] sm:h-[125px] md:h-[140px] xl:h-[160px] w-auto object-contain hover:scale-105 transition-transform duration-300 [filter:drop-shadow(0px_2px_6px_rgba(0,0,0,0.9))_drop-shadow(0px_0px_20px_rgba(0,0,0,0.85))] lg:[filter:none]" />
        </div>

        <div x-data="loginPage({ waNumber: '6285168112098', autoSlideMs: 4600 })"
             x-init="startSlider()"
             class="grid w-full items-stretch gap-4 lg:gap-6 xl:grid-cols-12">
            {{-- Left Side: Rounded Background Banner (Desktop Only) --}}
            <div class="login-hero-panel hidden xl:flex relative min-h-[640px] rounded-[2rem] overflow-hidden shadow-2xl xl:col-span-7"
                 @mouseenter="pauseSlider()"
                 @mouseleave="startSlider()">
                <div class="absolute inset-0 bg-cover bg-center bg-no-repeat" style="background-image: url('{{ asset('images/bg-login.png') }}'); background-size: cover; background-position: center;"></div>
                {{-- Inner subtle gradient for text readability and elegance --}}
                <div class="login-banner-overlay absolute inset-0"></div>
                <div class="login-hero-glow absolute -right-20 top-20"></div>
                <div class="login-hero-rings absolute inset-x-0 top-14 flex justify-center pointer-events-none">
                    <div class="login-hero-ring login-hero-ring--outer"></div>
                    <div class="login-hero-ring login-hero-ring--inner"></div>
                </div>
                
                {{-- Logo on image --}}
                <div class="absolute top-8 left-8 z-20 text-white font-extrabold text-[2rem] flex items-center gap-3 tracking-tight font-headline">
                    <div class="w-11 h-11 rounded-[14px] bg-white/10 backdrop-blur-xl border border-white/20 flex items-center justify-center shadow-2xl">
                        <x-icon name="architecture" class="w-5 h-5 text-white" />
                    </div>
                    E-REPORT
                </div>

                <div class="relative z-10 flex-1">
                    <article x-show="activeSlide === 0"
                             x-transition:enter="transition ease-out duration-700"
                             x-transition:enter-start="opacity-0 translate-y-6 scale-[0.98]"
                             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                             x-transition:leave="transition ease-in duration-400"
                             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                             x-transition:leave-end="opacity-0 -translate-y-3 scale-[0.98]"
                             class="login-hero-slide">
                        <div class="login-hero-copy">
                            <span class="login-hero-kicker">CRM Interior</span>
                            <h2 class="login-hero-title">Lead interior tertata, tim lebih sigap.</h2>
                            <p class="login-hero-description">Semua percakapan, status follow up, dan progres akun dirangkum dalam satu dashboard yang cepat dibaca.</p>
                        </div>

                        <div class="login-daisy-phone-scene">
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
                        </div>
                    </article>

                    <article x-show="activeSlide === 1"
                             x-transition:enter="transition ease-out duration-700"
                             x-transition:enter-start="opacity-0 translate-y-6 scale-[0.98]"
                             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                             x-transition:leave="transition ease-in duration-400"
                             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                             x-transition:leave-end="opacity-0 -translate-y-3 scale-[0.98]"
                             class="login-hero-slide">
                        <div class="login-hero-copy">
                            <span class="login-hero-kicker">Kontrol Tim</span>
                            <h2 class="login-hero-title">Pantau ritme admin tanpa kehilangan detail.</h2>
                            <p class="login-hero-description">Lihat akun aktif, respons tim, dan progres target konversi dalam panel yang rapi untuk dipakai harian.</p>
                        </div>

                        <div class="login-daisy-phone-scene login-daisy-phone-scene--alt">
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
                        </div>
                    </article>

                    <article x-show="activeSlide === 2"
                             x-transition:enter="transition ease-out duration-700"
                             x-transition:enter-start="opacity-0 translate-y-6 scale-[0.98]"
                             x-transition:enter-end="opacity-100 translate-y-0 scale-100"
                             x-transition:leave="transition ease-in duration-400"
                             x-transition:leave-start="opacity-100 translate-y-0 scale-100"
                             x-transition:leave-end="opacity-0 -translate-y-3 scale-[0.98]"
                             class="login-hero-slide">
                        <div class="login-hero-copy">
                            <span class="login-hero-kicker">Laporan Cerdas</span>
                            <h2 class="login-hero-title">Ambil keputusan lebih cepat dari dashboard.</h2>
                            <p class="login-hero-description">Panel dirancang fokus pada angka penting, jadi tim tidak tenggelam di data yang berulang.</p>
                        </div>

                        <div class="login-daisy-phone-scene login-daisy-phone-scene--focus">
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
                        </div>
                    </article>
                </div>

                <div class="absolute bottom-10 left-10 right-10 z-20 flex items-end justify-between">
                    <div>
                        <div class="login-hero-meta">Showcase produk</div>
                        <div class="login-hero-dots mt-4">
                            <button type="button" class="login-hero-dot" :class="{ 'is-active': activeSlide === 0 }" @click="setSlide(0)" aria-label="Slide 1"></button>
                            <button type="button" class="login-hero-dot" :class="{ 'is-active': activeSlide === 1 }" @click="setSlide(1)" aria-label="Slide 2"></button>
                            <button type="button" class="login-hero-dot" :class="{ 'is-active': activeSlide === 2 }" @click="setSlide(2)" aria-label="Slide 3"></button>
                        </div>
                    </div>

                    <div class="login-hero-status">
                        <span class="login-hero-status__label">Slide</span>
                        <span class="login-hero-status__value" x-text="String(activeSlide + 1).padStart(2, '0')"></span>
                    </div>
                </div>
            </div>

            {{-- Right Side: Login Form --}}
            <div class="flex w-full items-center justify-center xl:justify-center xl:col-span-5">
                <div class="login-panel w-full max-w-[440px] animate-fade-in rounded-[2rem] p-5 sm:p-6 md:p-7 xl:p-8">
                    
                    {{-- Mobile Logo (Only visible < xl) --}}
                    <div class="xl:hidden flex justify-center mb-6 sm:mb-7">
                        <div class="w-12 h-12 rounded-[14px] bg-gradient-to-br from-primary to-primary-dim flex items-center justify-center shadow-lg shadow-primary/30">
                            <x-icon name="architecture" class="w-6 h-6 text-white" />
                        </div>
                    </div>

                    {{-- Header --}}
                    <div class="mb-7 sm:mb-8 text-center xl:text-left">
                        <h1 class="text-[1.95rem] sm:text-[2.2rem] lg:text-[2.5rem] font-semibold leading-[1.05] mb-2 tracking-tight">Selamat Datang</h1>
                        <p class="login-muted text-sm sm:text-[15px] font-medium">Masuk ke dashboard CRM Anda</p>
                    </div>

                    @if($errors->any())
                    <div class="bg-error/10 border border-error/20 text-error px-4 py-3 rounded-xl text-xs sm:text-sm font-medium flex items-center gap-3 mb-6 shadow-inner">
                        <x-icon name="error" class="w-4 h-4 shrink-0" />
                        {{ $errors->first() }}
                    </div>
                    @endif

                    <form method="POST" action="{{ route('login.authenticate') }}" class="space-y-4 sm:space-y-5">
                        @csrf

                        {{-- Email Input (Engraved inside look) --}}
                        <div class="relative group">
                            <label for="email" class="sr-only">Alamat Email</label>
                            <input type="email" id="email" name="email" value="{{ old('email') }}"
                                   class="login-input login-autofill w-full rounded-xl px-4 py-3.5 sm:px-5 sm:py-4 text-sm sm:text-[15px] outline-none transition-all"
                                   placeholder="Masukkan alamat email" autocomplete="email" required autofocus />
                        </div>

                        {{-- Password Input (Engraved inside look) --}}
                        <div class="relative group">
                            <label for="password" class="sr-only">Kata Sandi</label>
                            <input type="password" id="password" name="password"
                                   class="login-input login-autofill w-full rounded-xl px-4 py-3.5 sm:px-5 sm:py-4 text-sm sm:text-[15px] outline-none transition-all"
                                   placeholder="Masukkan kata sandi" autocomplete="current-password" required />
                        </div>

                        {{-- Actions --}}
                        <div class="flex items-center justify-between pt-1 pb-3 sm:pb-4">
                            <label class="flex items-center gap-3 cursor-pointer group/chk">
                                <div class="relative flex items-center justify-center w-[16px] h-[16px] sm:w-[18px] sm:h-[18px] rounded-[5px] border border-outline-variant/50 bg-surface-container-low shadow-inner group-hover/chk:border-primary transition-colors">
                                    <input type="checkbox" name="remember" {{ old('remember') ? 'checked' : '' }} class="absolute opacity-0 w-full h-full cursor-pointer peer">
                                    <x-icon name="check" class="w-3 h-3 text-primary opacity-0 peer-checked:opacity-100 transition-opacity" />
                                </div>
                                <span class="login-muted text-xs sm:text-[14px] font-medium transition-colors group-hover/chk:text-on-surface">Ingat saya</span>
                            </label>
                        </div>

                        <button type="submit"
                                class="w-full bg-primary hover:bg-primary-dim text-on-primary py-3.5 sm:py-4 rounded-xl font-semibold text-sm sm:text-[15px] tracking-wide shadow-[0_8px_20px_rgba(0,0,0,0.2)] hover:shadow-[0_8px_25px_rgba(255,160,0,0.2)] transition-all active:scale-[0.98]">
                            Masuk
                        </button>

                    </form>

                    {{-- Support Separator --}}
                    <div class="mt-8 sm:mt-9 flex items-center justify-center gap-4">
                        <div class="h-px bg-gradient-to-r from-transparent via-outline-variant/60 to-transparent flex-1"></div>
                        <span class="login-muted text-[10px] sm:text-[11px] font-semibold uppercase tracking-widest whitespace-nowrap">Bantuan Aplikasi</span>
                        <div class="h-px bg-gradient-to-l from-transparent via-outline-variant/60 to-transparent flex-1"></div>
                    </div>

                    <div class="mt-5 sm:mt-6 text-center">
                        <button type="button" @click="showBugModal = true" class="login-support-btn inline-flex items-center gap-2 text-xs sm:text-sm font-medium transition-all rounded-xl px-5 py-3.5 w-full justify-center">
                            <x-icon name="bug_report" class="w-4 h-4" opacity="0.8" />
                            Hubungi Developer (Lapor Bug)
                        </button>
                    </div>

                    {{-- Bug Report Modal --}}
                    <template x-teleport="body">
                        <div x-show="showBugModal" x-cloak class="login-modal-backdrop fixed inset-0 z-[100] flex items-center justify-center p-4"
                             x-transition.opacity.duration.400ms>
                            <div @click.away="showBugModal = false" class="login-modal w-full max-w-md rounded-[2rem] shadow-2xl animate-fade-in overflow-hidden mx-2">
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
                                    <p class="text-xs sm:text-[14px] text-on-surface-variant mb-6 leading-relaxed">Punya laporan mengenai sistem yang error atau tidak berjalan semestinya? Jelaskan secara detail di bawah ini agar tim teknis kami bisa segera mengatasi.</p>
                                    
                                    <div class="space-y-5 sm:space-y-6">
                                        <textarea x-model="bugMessage" rows="5" 
                                                  @input="bugError = ''"
                                                  class="login-input w-full rounded-xl px-4 sm:px-5 py-3 sm:py-4 text-xs sm:text-[14px] resize-none outline-none transition-all"
                                                  :class="bugError ? 'ring-2 ring-error/50' : ''"
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
                                                class="w-full bg-[#25D366] hover:bg-[#20bd5a] text-white py-3 sm:py-4 rounded-xl font-semibold flex items-center justify-center gap-2.5 shadow-[0_8px_20px_rgba(37,211,102,0.2)] transition-all active:scale-[0.98] text-sm sm:text-base">
                                            <x-icon name="chat" class="w-[18px] h-[18px]" />
                                            <span>Kirim Laporan via WhatsApp</span>
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
