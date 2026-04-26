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

    @yield('content')
</body>
</html>
