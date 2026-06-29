<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>PENDING APPROVAL - MOCKBUILD</title>
    <!-- Favicon -->
    <link rel="icon" type="image/png" href="/logo/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <style>
        body {
            font-family: 'Courier New', Courier, monospace;
        }
    </style>
</head>
<body class="bg-black text-zinc-100 flex items-center justify-center min-h-screen p-6 select-none">
    <div class="max-w-md w-full border border-zinc-800 bg-zinc-950 p-8 space-y-6 text-center">
        <!-- Badge -->
        <div class="inline-block border border-yellow-500/30 bg-yellow-500/10 text-yellow-500 text-xs px-2.5 py-1 uppercase tracking-wider font-bold">
            Awaiting Approval
        </div>

        <!-- Heading -->
        <h1 class="text-lg font-bold tracking-widest text-white uppercase">
            Persetujuan Tertunda
        </h1>

        <!-- Details -->
        <div class="text-left space-y-2 border-y border-zinc-900 py-4 font-mono text-xs text-zinc-400">
            <div>
                <span class="text-zinc-600 block">SUBDOMAIN / SLUG:</span>
                <span class="text-white font-bold">{{ $deployment->client_slug }}</span>
            </div>
            <div>
                <span class="text-zinc-600 block">STATUS PESANAN:</span>
                <span class="text-yellow-500 uppercase">{{ $deployment->status }}</span>
            </div>
            @if($deployment->price)
            <div>
                <span class="text-zinc-600 block">TOTAL BIAYA:</span>
                <span class="text-white">Rp {{ number_format($deployment->price, 0, ',', '.') }}</span>
            </div>
            @endif
        </div>

        <!-- Info Message -->
        <p class="text-xs text-zinc-500 leading-relaxed">
            Berkas PDF untuk pemesanan ini telah berhasil dibuat di server, namun belum diaktifkan oleh Administrator.<br><br>
            Silakan lakukan pembayaran atau hubungi Admin untuk melakukan verifikasi. Berkas akan otomatis tersedia untuk diunduh setelah status berubah menjadi <b class="text-white">ACTIVE</b>.
        </p>

        <!-- Back Button -->
        <div class="pt-4">
            <a href="/" class="inline-block w-full border border-zinc-800 text-zinc-400 hover:text-white hover:border-white transition-colors duration-200 text-xs py-2 uppercase tracking-widest font-bold">
                Kembali Ke Toko
            </a>
        </div>
    </div>
</body>
</html>
