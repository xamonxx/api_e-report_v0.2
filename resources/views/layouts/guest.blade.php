<!DOCTYPE html>
<html class="light" lang="id">
<head>
    <meta charset="utf-8"/>
    <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>Login | E-REPORT</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('images/logo_e-report_transparan.svg') }}">
    <link rel="shortcut icon" href="{{ asset('images/logo_e-report_transparan.svg') }}">
    <script>
        (() => {
            try {
                const key = 'e-report-theme';
                const stored = window.localStorage.getItem(key);
                const mode = stored === 'dark' || stored === 'light'
                    ? stored
                    : (window.matchMedia('(prefers-color-scheme: dark)').matches ? 'dark' : 'light');
                const root = document.documentElement;
                root.classList.remove('light', 'dark');
                root.classList.add(mode);
                root.dataset.theme = mode;
                root.style.colorScheme = mode;
            } catch (_error) {
                document.documentElement.classList.add('light');
            }
        })();
    </script>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>
<body x-data="themeController()" x-init="initTheme()" class="font-sans antialiased text-on-surface bg-surface min-h-screen">
    <div class="fixed top-4 right-4 z-50">
        <button type="button"
                @click="toggleTheme()"
                :aria-label="themeMode === 'dark' ? 'Aktifkan mode terang' : 'Aktifkan mode gelap'"
                :title="themeMode === 'dark' ? 'Ganti ke light mode' : 'Ganti ke dark mode'"
                class="theme-toggle-btn theme-toggle-btn--floating"
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
    </div>
    @yield('content')
</body>
</html>
