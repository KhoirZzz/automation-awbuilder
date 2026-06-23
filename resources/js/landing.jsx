import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom/client';
import { Card } from './components/Card';
import { Button } from './components/Button';
import { Alert } from './components/Alert';

function Landing() {
    const [templates, setTemplates] = useState([]);
    const [loadingTemplates, setLoadingTemplates] = useState(true);
    const [selectedTemplate, setSelectedTemplate] = useState(null);
    const [durasi, setDurasi] = useState('1_bulan');
    const [clientSlug, setClientSlug] = useState('');
    const [telegramToken, setTelegramToken] = useState('');
    const [telegramChatId, setTelegramChatId] = useState('');
    
    const [loading, setLoading] = useState(false);
    const [alert, setAlert] = useState(null);
    const [checkoutResult, setCheckoutResult] = useState(null); // { url, price, deployment }

    // Fetch active blueprints
    useEffect(() => {
        const fetchTemplates = async () => {
            try {
                const res = await fetch('/api/public/templates');
                const data = await res.json();
                setTemplates(data);
                if (data.length > 0) {
                    setSelectedTemplate(data[0]);
                }
            } catch (e) {
                console.error('Error fetching templates', e);
            } finally {
                setLoadingTemplates(false);
            }
        };
        fetchTemplates();
    }, []);

    // Price calculation based on duration
    const getPrice = (duration) => {
        switch (duration) {
            case '1_minggu': return { numeric: 50000, formatted: 'Rp 50.000' };
            case '1_bulan': return { numeric: 150000, formatted: 'Rp 150.000' };
            case '3_bulan': return { numeric: 400000, formatted: 'Rp 400.000' };
            case '6_bulan': return { numeric: 750000, formatted: 'Rp 750.000' };
            case '1_tahun': return { numeric: 1200000, formatted: 'Rp 1.200.000' };
            default: return { numeric: 150000, formatted: 'Rp 150.000' };
        }
    };

    const handleCheckout = async (e) => {
        e.preventDefault();
        if (!selectedTemplate) {
            setAlert({ type: 'error', message: 'Silakan pilih layanan terlebih dahulu.' });
            return;
        }

        const cleanSlug = clientSlug.trim().toLowerCase();
        if (!cleanSlug) {
            setAlert({ type: 'error', message: 'Subdomain / Slug harus diisi.' });
            return;
        }

        setLoading(true);
        setAlert(null);

        try {
            const res = await fetch('/api/public/deploy', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    service_key: selectedTemplate.key,
                    durasi,
                    client_slug_request: cleanSlug,
                    telegram_token: telegramToken,
                    telegram_chat_id: telegramChatId,
                })
            });
            const data = await res.json();

            if (data.success) {
                setCheckoutResult(data);
                setAlert({ type: 'success', message: 'Pesanan berhasil dikonfigurasi! Silakan lakukan pembayaran.' });
            } else {
                setAlert({ type: 'error', message: data.error || 'Gagal membuat pesanan.' });
            }
        } catch (err) {
            setAlert({ type: 'error', message: 'Gagal menghubungi server store.' });
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="flex flex-col min-h-screen bg-black text-zinc-100 font-mono selection:bg-zinc-800 selection:text-white">
            {/* Header */}
            <header className="border-b border-zinc-850 bg-zinc-950/80 backdrop-blur-md sticky top-0 z-30 px-6 py-4 flex items-center justify-between">
                <div className="flex items-center gap-3">
                    <img 
                        src="/logo/awbuilder.png" 
                        alt="AWBuilder" 
                        className="h-8 w-8 object-cover rounded-full border border-zinc-800"
                        onError={(e) => { e.target.style.display = 'none'; }}
                    />
                    <div>
                        <span className="font-bold text-zinc-100 tracking-tight text-sm uppercase block">AWBUILDER STORE</span>
                        <span className="text-[9px] text-zinc-500 tracking-widest uppercase block -mt-1">Self-Service Auto Deployment</span>
                    </div>
                </div>
                <div className="flex items-center gap-2">
                    <span className="h-1.5 w-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                    <span className="text-[10px] text-zinc-550 uppercase tracking-widest">Automation Engine Online</span>
                </div>
            </header>

            {/* Main Store Layout */}
            <main className="flex-1 max-w-7xl w-full mx-auto px-6 py-10 md:py-16 space-y-12">
                
                {/* Hero Section */}
                <div className="text-center space-y-3 max-w-3xl mx-auto">
                    <h1 className="text-2xl md:text-4xl font-extrabold text-white tracking-tight uppercase">
                        Instant App Provisioning
                    </h1>
                    <p className="text-zinc-500 text-xs md:text-sm leading-relaxed max-w-xl mx-auto">
                        Pilih template layanan, masukkan konfigurasi bot Telegram Anda, dan luncurkan instansi VPS secara instan.
                    </p>
                </div>

                {alert && (
                    <div className="max-w-xl mx-auto">
                        <Alert
                            type={alert.type}
                            message={alert.message}
                            onClose={() => setAlert(null)}
                        />
                    </div>
                )}

                {checkoutResult ? (
                    /* Checkout Success & QRIS Payment View */
                    <div className="max-w-2xl mx-auto">
                        <Card title="📄 INVOICE PEMESANAN & INTRUKSI PEMBAYARAN">
                            <div className="space-y-6 my-2 text-xs">
                                <div className="p-4 bg-zinc-950 border border-zinc-850 rounded space-y-2">
                                    <div className="flex justify-between border-b border-zinc-900 pb-2">
                                        <span className="text-zinc-500">Layanan Blueprint:</span>
                                        <span className="text-zinc-100 font-bold">{selectedTemplate?.name} ({selectedTemplate?.key})</span>
                                    </div>
                                    <div className="flex justify-between border-b border-zinc-900 pb-2">
                                        <span className="text-zinc-500">Subdomain / URL target:</span>
                                        <span className="text-zinc-100 font-bold underline">
                                            {checkoutResult.url}
                                        </span>
                                    </div>
                                    <div className="flex justify-between border-b border-zinc-900 pb-2">
                                        <span className="text-zinc-500">Durasi Sewa:</span>
                                        <span className="text-zinc-100 font-bold uppercase">{durasi.replace('_', ' ')}</span>
                                    </div>
                                    <div className="flex justify-between pt-1">
                                        <span className="text-white font-semibold">Total Pembayaran:</span>
                                        <span className="text-white font-extrabold text-xs">
                                            {checkoutResult.price ? `Rp ${Number(checkoutResult.price).toLocaleString('id-ID')}` : 'Menunggu konfirmasi Admin (Hubungi @awbuilderadmin)'}
                                        </span>
                                    </div>
                                </div>

                                <div className="flex flex-col md:flex-row items-center gap-6 p-4 border border-zinc-850 rounded bg-zinc-950">
                                    <div className="shrink-0 bg-white p-2 rounded">
                                        {/* Real QRIS code from static logo directory */}
                                        <img 
                                            src="/logo/qris.png" 
                                            alt="QRIS Payment" 
                                            className="h-32 w-32 object-contain"
                                        />
                                    </div>
                                    <div className="space-y-2 text-[11px] leading-relaxed text-zinc-400">
                                        <span className="font-bold text-white uppercase block text-xs">Langkah Pembayaran:</span>
                                        <ol className="list-decimal pl-4 space-y-1.5">
                                            <li>Scan kode QRIS di samping menggunakan aplikasi e-wallet atau mobile banking Anda.</li>
                                            <li>Kirimkan bukti transfer pembayaran Anda ke Admin Telegram kami di: <a href="https://t.me/awbuilderadmin" target="_blank" rel="noopener noreferrer" className="text-white font-bold underline hover:text-zinc-300">@awbuilderadmin</a>.</li>
                                            <li>Begitu pembayaran diverifikasi, admin akan memberikan persetujuan (approval) secara instan.</li>
                                            <li>Aplikasi Anda akan segera aktif dan dapat diakses di tautan URL di atas.</li>
                                        </ol>
                                    </div>
                                </div>

                                <div className="flex gap-3">
                                    <Button 
                                        variant="secondary" 
                                        onClick={() => {
                                            setCheckoutResult(null);
                                            setClientSlug('');
                                            setTelegramToken('');
                                            setTelegramChatId('');
                                        }} 
                                        className="flex-1 uppercase font-bold text-xs"
                                    >
                                        Beli Layanan Lain
                                    </Button>
                                    <a 
                                        href="https://t.me/awbuilderadmin" 
                                        target="_blank" 
                                        rel="noopener noreferrer" 
                                        className="flex-1"
                                    >
                                        <Button variant="primary" className="w-full uppercase font-bold text-xs">
                                            Kirim Bukti ke Telegram Admin
                                        </Button>
                                    </a>
                                </div>
                            </div>
                        </Card>
                    </div>
                ) : (
                    /* Public Store Checkout View */
                    <div className="grid grid-cols-1 lg:grid-cols-12 gap-8 items-start">
                        
                        {/* Blueprints Catalog List */}
                        <div className="lg:col-span-7 space-y-5">
                            <h2 className="text-xs font-bold text-zinc-500 uppercase tracking-widest">Katalog Layanan Tersedia</h2>
                            {loadingTemplates ? (
                                <div className="p-12 border border-dashed border-zinc-850 text-center text-zinc-500 text-xs font-mono uppercase">
                                    Loading active blueprint catalog...
                                </div>
                            ) : templates.length === 0 ? (
                                <div className="p-12 border border-dashed border-zinc-850 text-center text-zinc-500 text-xs font-mono uppercase">
                                    No services are currently active in the store catalog.
                                </div>
                            ) : (
                                <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                                    {templates.map((t) => (
                                        <div 
                                            key={t.key}
                                            onClick={() => setSelectedTemplate(t)}
                                            className={`p-5 border rounded cursor-pointer transition-all flex flex-col justify-between h-44 ${
                                                selectedTemplate?.key === t.key
                                                    ? 'bg-zinc-950 border-white text-white shadow-[0_0_10px_rgba(255,255,255,0.05)]'
                                                    : 'bg-zinc-950/40 border-zinc-850 hover:border-zinc-700 text-zinc-400'
                                            }`}
                                        >
                                            <div className="space-y-2">
                                                <span className="text-[10px] uppercase tracking-wider text-zinc-500 block font-bold">
                                                    {t.category || 'App Service'}
                                                </span>
                                                <span className={`text-sm font-extrabold uppercase ${
                                                    selectedTemplate?.key === t.key ? 'text-white' : 'text-zinc-200'
                                                }`}>
                                                    {t.name}
                                                </span>
                                            </div>
                                            <div className="flex justify-between items-end pt-4 border-t border-zinc-900/60 mt-auto">
                                                <span className="text-[10px] text-zinc-500 font-semibold uppercase">Base Template</span>
                                                <span className={`text-[10px] border px-2 py-0.5 rounded uppercase font-bold tracking-wider ${
                                                    selectedTemplate?.key === t.key
                                                        ? 'bg-white text-black border-white'
                                                        : 'bg-zinc-900 text-zinc-500 border-zinc-800'
                                                }`}>
                                                    {t.key}
                                                </span>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </div>

                        {/* Checkout Form */}
                        <div className="lg:col-span-5">
                            <Card title="⚡ KONFIGURASI INSTANSI & CEKOUT">
                                {selectedTemplate ? (
                                    <form onSubmit={handleCheckout} className="space-y-4 text-xs my-1">
                                        <div className="p-3 bg-zinc-950 border border-zinc-850 rounded">
                                            <span className="text-[10px] text-zinc-500 block uppercase tracking-wider font-semibold">Layanan Terpilih</span>
                                            <span className="text-white font-extrabold uppercase text-xs block mt-0.5">{selectedTemplate.name}</span>
                                        </div>

                                        <div className="space-y-1">
                                            <label className="block font-semibold text-zinc-400 uppercase">Pilih Durasi</label>
                                            <select
                                                value={durasi}
                                                onChange={(e) => setDurasi(e.target.value)}
                                                className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2.5 text-white focus:outline-none focus:border-zinc-500 font-mono"
                                            >
                                                <option value="1_minggu">1 Minggu</option>
                                                <option value="1_bulan">1 Bulan</option>
                                                <option value="3_bulan">3 Bulan</option>
                                                <option value="6_bulan">6 Bulan</option>
                                                <option value="1_tahun">1 Tahun</option>
                                            </select>
                                        </div>

                                        <div className="space-y-1">
                                            <label className="block font-semibold text-zinc-400 uppercase">Subdomain / Slug Klien</label>
                                            <div className="flex items-center bg-zinc-900 border border-zinc-800 rounded focus-within:border-zinc-500 overflow-hidden">
                                                <input
                                                    type="text"
                                                    required
                                                    value={clientSlug}
                                                    onChange={(e) => setClientSlug(e.target.value)}
                                                    placeholder="e.g. tokosaya"
                                                    className="flex-1 bg-transparent px-3 py-2.5 text-white placeholder-zinc-700 focus:outline-none font-mono"
                                                />
                                                <span className="bg-zinc-950 text-zinc-500 px-3 py-2.5 border-l border-zinc-800 text-[10px] uppercase font-bold">
                                                    .mockbuild.shop
                                                </span>
                                            </div>
                                        </div>

                                        <div className="space-y-1">
                                            <label className="block font-semibold text-zinc-400 uppercase">Token Bot Telegram Anda</label>
                                            <input
                                                type="text"
                                                required
                                                value={telegramToken}
                                                onChange={(e) => setTelegramToken(e.target.value)}
                                                placeholder="e.g. 123456:ABC-DEF..."
                                                className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2.5 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                            />
                                        </div>

                                        <div className="space-y-1">
                                            <label className="block font-semibold text-zinc-400 uppercase">Chat ID Telegram Anda</label>
                                            <input
                                                type="text"
                                                required
                                                value={telegramChatId}
                                                onChange={(e) => setTelegramChatId(e.target.value)}
                                                placeholder="e.g. 987654321"
                                                className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2.5 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                            />
                                        </div>

                                        <div className="pt-2 border-t border-zinc-900 flex justify-between items-center">
                                            <div>
                                                <span className="text-[10px] text-zinc-500 block uppercase">Biaya Layanan</span>
                                                <span className="text-white font-extrabold text-xs">Menunggu konfirmasi Admin (Hubungi @awbuilderadmin)</span>
                                            </div>
                                            <Button type="submit" variant="primary" loading={loading} className="uppercase font-bold tracking-wider text-xs">
                                                Pesan & Deploy Layanan
                                            </Button>
                                        </div>
                                    </form>
                                ) : (
                                    <div className="p-8 border border-dashed border-zinc-900 text-center text-zinc-500 text-xs font-mono uppercase">
                                        Select a blueprint from the catalog to configure.
                                    </div>
                                )}
                            </Card>
                        </div>
                    </div>
                )}
            </main>

            {/* Footer */}
            <footer className="border-t border-zinc-900 py-8 px-6 text-center text-[10px] text-zinc-650 font-mono uppercase tracking-wider">
                &copy; {new Date().getFullYear()} AWBuilder. All rights reserved. Managed under autonomous cloud engine.
            </footer>
        </div>
    );
}

const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(<Landing />);
