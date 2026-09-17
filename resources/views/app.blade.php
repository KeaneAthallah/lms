<!DOCTYPE html>
<html lang="en" class="h-full">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <link rel="icon" type="image/svg+xml" href="/favicon.svg">
    <link rel="icon" href="/favicon.ico" sizes="32x32">
    <meta name="csrf-token" content="{{ csrf_token() }}">
    <title>{{ config('lms.institution_name', 'LMS') }}</title>
    <script>
        (function () {
            try {
                var preference = localStorage.getItem('lms.theme');
                var dark = preference === 'dark' || (preference !== 'light' && window.matchMedia('(prefers-color-scheme: dark)').matches);
                document.documentElement.classList.toggle('dark', dark);
                document.documentElement.style.colorScheme = dark ? 'dark' : 'light';
            } catch (e) {
                /* storage unavailable — light theme (no .dark) is the fallback */
            }
        })();
    </script>
    @viteReactRefresh
    @vite(['resources/css/app.css', 'resources/js/app.jsx'])
</head>
<body class="h-full bg-slate-100 font-sans antialiased">
    <div id="app"></div>
    <script>
        window.__LMS_USER__ = @json($user);
        window.__LMS_CONFIG__ = {
            institutionName: @json(config('lms.institution_name', 'LMS')),
        };
    </script>
</body>
</html>