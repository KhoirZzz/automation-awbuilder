<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" class="h-full">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <!-- Primary Meta Tags -->
        <title>MockBuild - Auto-Deployment Sandbox Store</title>
        <meta name="title" content="MockBuild - Auto-Deployment Sandbox Store">
        <meta name="description" content="Deploy template dan instansi web custom Anda ke server sandbox secara otomatis dalam hitungan menit. Cepat, aman, dan terintegrasi dengan Bot Telegram.">
        
        <!-- Favicon -->
        <link rel="icon" type="image/png" href="/logo/favicon.png">

        <!-- Open Graph / Facebook / Telegram -->
        <meta property="og:type" content="website">
        <meta property="og:url" content="https://mockbuild.shop/">
        <meta property="og:title" content="MockBuild - Auto-Deployment Sandbox Store">
        <meta property="og:description" content="Deploy template dan instansi web custom Anda ke server sandbox secara otomatis dalam hitungan menit. Cepat, aman, dan terintegrasi dengan Bot Telegram.">
        <meta property="og:image" content="/logo/mockbuild.png">

        <!-- Twitter -->
        <meta property="twitter:card" content="summary_large_image">
        <meta property="twitter:url" content="https://mockbuild.shop/">
        <meta property="twitter:title" content="MockBuild - Auto-Deployment Sandbox Store">
        <meta property="twitter:description" content="Deploy template dan instansi web custom Anda ke server sandbox secara otomatis dalam hitungan menit. Cepat, aman, dan terintegrasi dengan Bot Telegram.">
        <meta property="twitter:image" content="/logo/mockbuild.png">

        <link rel="preconnect" href="https://fonts.googleapis.com">
        <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
        <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@300;400;500;600;700;800&family=Plus+Jakarta+Sans:wght@300;400;500;600;700;800&display=swap" rel="stylesheet">
        
        @viteReactRefresh
        @vite(['resources/css/app.css', 'resources/js/landing.jsx'])
    </head>
    <body class="h-full bg-black text-zinc-100 font-sans antialiased overflow-x-hidden">
        <div id="app" class="min-h-screen flex flex-col"></div>
    </body>
</html>
