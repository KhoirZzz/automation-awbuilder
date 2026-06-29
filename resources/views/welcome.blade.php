<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- Prevent Theme Flash -->
        <script>
            try {
                const savedTheme = localStorage.getItem('app_theme') || 'nihilist';
                document.documentElement.setAttribute('data-theme', savedTheme);
            } catch (e) {}
        </script>
        <title>MockBuild - Admin Control Center</title>
        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/logo/favicon.png">
        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        <!-- PWA Manifest & Meta Tags (Android & iOS) -->
        <link rel="manifest" href="/manifest.json">
        <meta name="theme-color" content="#000000">
        
        <!-- iOS PWA Specific Meta Tags -->
        <meta name="apple-mobile-web-app-capable" content="yes">
        <meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
        <meta name="apple-mobile-web-app-title" content="MockBuild">
        <link rel="apple-touch-icon" href="/logo/mockbuild.png">

        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/app.jsx'])

        <!-- Service Worker Registration -->
        <script>
            if ('serviceWorker' in navigator) {
                window.addEventListener('load', () => {
                    navigator.serviceWorker.register('/sw.js')
                        .then(reg => console.log('Service Worker registered successfully:', reg.scope))
                        .catch(err => console.error('Service Worker registration failed:', err));
                });
            }
        </script>
    </head>
    <body class="h-full bg-slate-950 text-slate-100 font-sans antialiased overflow-x-hidden">
        <div id="app" class="min-h-screen flex flex-col"></div>
    </body>
</html>
