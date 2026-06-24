import React, { useState, useEffect } from 'react';
import { Card } from '../components/Card';
import { Button } from '../components/Button';
import { Alert } from '../components/Alert';

const STAGES = [
    { key: 'webhook', label: '1. Webhook Payload Received', desc: 'Simulated WhatsApp/Telegram webhook event registered.' },
    { key: 'llm_analysis', label: '2. LLM Logic Extraction', desc: 'Hermes model extracts key details (service, duration, client slug).' },
    { key: 'validation', label: '3. Policy & Whitelist Validation', desc: 'Sanitizing slug rules, DNS compliance, blacklist check, and duplicate check.' },
    { key: 'replication', label: '4. Instance Directory Provisioning', desc: 'Cloning relative template paths and injecting local environment configs.' },
    { key: 'script_execution', label: '5. deploy.sh Execution', desc: 'Running local post-cloning setup script inside instance.' },
    { key: 'completed', label: '6. Instance Deployment Live', desc: 'Deployment record committed as active in database.' }
];

function calculatePriceForDuration(basePrice, duration) {
    if (!basePrice) return 0;
    const base = parseInt(basePrice, 10);
    switch (duration) {
        case '1_minggu':
            return base;
        case '1_bulan':
            if (base <= 75000) {
                return Math.round(base * 3.5);
            }
            return (base * 2) + 150000;
        case '3_bulan':
            const m3 = calculatePriceForDuration(base, '1_bulan');
            return Math.round(m3 * 3 * 0.9);
        case '6_bulan':
            const m6 = calculatePriceForDuration(base, '1_bulan');
            return Math.round(m6 * 6 * 0.8);
        case '1_tahun':
            const m12 = calculatePriceForDuration(base, '1_bulan');
            return Math.round(m12 * 12 * 0.7);
        default:
            return base;
    }
}

export default function Sandbox() {
    const [message, setMessage] = useState('');
    const [source, setSource] = useState('telegram');
    const [loading, setLoading] = useState(false);
    const [leadRef, setLeadRef] = useState(null);
    const [currentStatus, setCurrentStatus] = useState(null); // { stage, status, message, deployment }
    const [logs, setLogs] = useState('');

    // Alert state
    const [alert, setAlert] = useState(null); // { type, message }

    // Manual Form states
    const [templates, setTemplates] = useState([]);
    const [serviceKey, setServiceKey] = useState('');
    const [durasi, setDurasi] = useState('1_minggu');
    const [clientSlug, setClientSlug] = useState('');
    const [telegramToken, setTelegramToken] = useState('');
    const [telegramChatId, setTelegramChatId] = useState('');
    const [price, setPrice] = useState('');
    const [targetUrl, setTargetUrl] = useState('');
    const [outputPdf, setOutputPdf] = useState('');

    // Fetch active templates
    useEffect(() => {
        const fetchTemplates = async () => {
            try {
                const res = await fetch('/api/dashboard/templates');
                const data = await res.json();
                const active = data.filter(t => t.is_active);
                setTemplates(active);
                if (active.length > 0) {
                    setServiceKey(active[0].key);
                }
            } catch (e) {
                console.error('Error fetching templates', e);
            }
        };
        fetchTemplates();
    }, []);

    // Sync price automatically when serviceKey or durasi changes based on template's configured price
    useEffect(() => {
        if (!serviceKey) return;
        const selectedTemplate = templates.find(t => t.key === serviceKey);
        if (selectedTemplate && selectedTemplate.price !== null && selectedTemplate.price !== undefined) {
            const basePrice = selectedTemplate.price;
            const calculated = calculatePriceForDuration(basePrice, durasi);
            setPrice(calculated.toString());
        } else {
            setPrice('');
        }
    }, [serviceKey, durasi, templates]);

    const handleManualDeploy = async (e) => {
        e.preventDefault();
        
        if (!serviceKey) {
            setAlert({ type: 'error', message: 'Kategori/Template harus dipilih.' });
            return;
        }

        const cleanSlug = clientSlug.trim().toLowerCase();
        if (!cleanSlug) {
            setAlert({ type: 'error', message: 'Subdomain / Slug harus diisi.' });
            return;
        }

        setLoading(true);
        setLeadRef(null);
        setCurrentStatus(null);
        setAlert(null);
        setLogs('Queuing manual deployment event to database queue... awaiting worker execution.');

        try {
            const res = await fetch('/api/dashboard/sandbox/manual-deploy', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    service_key: serviceKey,
                    durasi: durasi,
                    client_slug_request: cleanSlug,
                    telegram_token: telegramToken,
                    telegram_chat_id: telegramChatId,
                    price: price ? parseFloat(price) : null,
                    target_url: targetUrl || null,
                    output_pdf: outputPdf || null
                })
            });
            const data = await res.json();
            
            if (data.success) {
                setLeadRef(data.lead_reference);
                setAlert({ type: 'success', message: 'Deployment manual berhasil dimasukkan ke sandbox queue! Memulai proses...' });
            } else {
                setAlert({ type: 'error', message: data.error || 'Gagal mengirim konfigurasi deployment.' });
                setLogs('Failed to queue manual deployment: ' + (data.error || 'Unknown error'));
                setLoading(false);
            }
        } catch (err) {
            setAlert({ type: 'error', message: 'Gagal menghubungi API server.' });
            setLogs('Failed to communicate with API.');
            setLoading(false);
        }
    };
    
    // Status polling interval
    useEffect(() => {
        if (!leadRef) return;

        let intervalId;
        const pollStatus = async () => {
            try {
                const res = await fetch(`/api/dashboard/sandbox/status/${leadRef}`);
                const data = await res.json();
                setCurrentStatus(data);

                // Stop polling if completed or failed
                if (data.stage === 'completed') {
                    clearInterval(intervalId);
                    setLoading(false);
                    // Fetch full logs one last time to capture output
                    const logRes = await fetch('/api/dashboard/logs');
                    const logData = await logRes.json();
                    setLogs(logData.logs || '');
                }
            } catch (e) {
                console.error('Polling error', e);
            }
        };

        // Poll immediately and then every 1.5s
        pollStatus();
        intervalId = setInterval(pollStatus, 1500);

        return () => clearInterval(intervalId);
    }, [leadRef]);

    const handleSimulate = async (e) => {
        e.preventDefault();
        setLoading(true);
        setLeadRef(null);
        setCurrentStatus(null);
        setAlert(null);
        setLogs('Dispatched webhook event to database queue... awaiting worker execution.');

        try {
            const res = await fetch('/api/dashboard/sandbox/test', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ message, source })
            });
            const data = await res.json();
            
            if (data.success) {
                setLeadRef(data.lead_reference);
            } else {
                setLogs('Failed to trigger webhook simulation: ' + (data.error || 'Unknown error'));
                setLoading(false);
            }
        } catch (err) {
            setLogs('Failed to communicate with API.');
            setLoading(false);
        }
    };

    const getStageState = (stageKey) => {
        if (!currentStatus) return 'waiting';

        const { stage, status } = currentStatus;

        if (stageKey === 'webhook') return 'success';

        // 1. Exact matching for cache-based high-fidelity stages
        const stageOrder = ['webhook', 'llm_analysis', 'validation', 'replication', 'script_execution', 'completed'];
        
        if (stageOrder.includes(stage)) {
            const currentStageIndex = stageOrder.indexOf(stage);
            const targetStageIndex = stageOrder.indexOf(stageKey);

            if (status === 'failed') {
                if (stageKey === stage) return 'error';
                if (targetStageIndex < currentStageIndex) return 'success';
                return 'waiting';
            }

            if (status === 'pending') {
                if (stageKey === stage) return 'pending';
                if (targetStageIndex < currentStageIndex) return 'success';
                return 'waiting';
            }

            if (status === 'active' || status === 'completed') {
                return 'success';
            }
        }

        // 2. Fallback for legacy database-based stages (for compatibility)
        if (stage === 'llm_analysis') {
            if (stageKey === 'llm_analysis') return 'pending';
            return 'waiting';
        }

        if (stage === 'deploying') {
            if (['llm_analysis', 'validation'].includes(stageKey)) return 'success';
            if (['replication', 'script_execution'].includes(stageKey)) return 'pending';
            return 'waiting';
        }

        if (stage === 'completed') {
            if (status === 'active') return 'success';
            if (status === 'failed') {
                if (['llm_analysis', 'validation', 'replication'].includes(stageKey)) return 'success';
                if (stageKey === 'script_execution') return 'error';
                return 'error';
            }
        }

        return 'waiting';
    };

    const getIndicatorStyle = (state) => {
        switch (state) {
            case 'success':
                return 'bg-white border-white text-black';
            case 'pending':
                return 'bg-zinc-900 border-zinc-500 text-zinc-300 animate-pulse';
            case 'error':
                return 'bg-black border-red-800 text-red-500';
            case 'waiting':
            default:
                return 'bg-zinc-950 border-zinc-900 text-zinc-700';
        }
    };

    return (
        <div className="space-y-8 font-mono">
            <div>
                <h1 className="text-xl font-bold text-white tracking-tight uppercase">Simulation Sandbox</h1>
                <p className="text-zinc-500 text-xs mt-1">Test the LLM parser, DNS policies, templates replication, and process hooks.</p>
            </div>

            {alert && (
                <div className="max-w-xl">
                    <Alert
                        type={alert.type}
                        message={alert.message}
                        onClose={() => setAlert(null)}
                    />
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* Form Control */}
                <div className="lg:col-span-4 space-y-6">
                    <Card title="Trigger Webhook Event">
                        <form onSubmit={handleSimulate} className="space-y-5 text-xs">
                            <div className="space-y-2">
                                <label className="block font-semibold text-zinc-400 uppercase">Gateway Channel</label>
                                <div className="flex gap-3">
                                    <label className={`flex-1 flex items-center justify-center gap-2 border rounded px-3 py-2.5 cursor-pointer font-semibold uppercase transition-colors ${
                                        source === 'telegram'
                                            ? 'bg-white text-black border-white'
                                            : 'bg-zinc-900 text-zinc-500 border-zinc-800 hover:bg-zinc-800'
                                    }`}>
                                        <input
                                            type="radio"
                                            name="source"
                                            value="telegram"
                                            checked={source === 'telegram'}
                                            onChange={() => setSource('telegram')}
                                            className="hidden"
                                        />
                                        Telegram
                                    </label>
                                    <label className={`flex-1 flex items-center justify-center gap-2 border rounded px-3 py-2.5 cursor-pointer font-semibold uppercase transition-colors ${
                                        source === 'whatsapp'
                                            ? 'bg-white text-black border-white'
                                            : 'bg-zinc-900 text-zinc-500 border-zinc-800 hover:bg-zinc-800'
                                    }`}>
                                        <input
                                            type="radio"
                                            name="source"
                                            value="whatsapp"
                                            checked={source === 'whatsapp'}
                                            onChange={() => setSource('whatsapp')}
                                            className="hidden"
                                        />
                                        WhatsApp
                                    </label>
                                </div>
                            </div>

                            <div className="space-y-2">
                                <label className="block font-semibold text-zinc-400 uppercase">Raw Lead Message</label>
                                <textarea
                                    required
                                    rows="5"
                                    value={message}
                                    onChange={(e) => setMessage(e.target.value)}
                                    placeholder="e.g. Tolong buatkan shopee-bot untuk client 'tokogede' selama 1 minggu."
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2.5 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 resize-none leading-relaxed font-mono"
                                />
                            </div>

                            <Button type="submit" variant="primary" loading={loading} className="w-full uppercase font-semibold text-xs tracking-wider">
                                Dispatch Event
                            </Button>
                        </form>
                    </Card>

                    <Card title="Manual Deployment Form">
                        <form onSubmit={handleManualDeploy} className="space-y-4 text-xs">
                            <div className="space-y-1">
                                <label className="block font-semibold text-zinc-400 uppercase">Kategori / Blueprint</label>
                                <select
                                    value={serviceKey}
                                    onChange={(e) => setServiceKey(e.target.value)}
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white focus:outline-none focus:border-zinc-500 font-mono"
                                >
                                    {templates.length === 0 ? (
                                        <option value="">No templates available</option>
                                    ) : (
                                        templates.map((t) => (
                                            <option key={t.key} value={t.key}>
                                                {t.name} ({t.key})
                                            </option>
                                        ))
                                    )}
                                </select>
                            </div>

                            <div className="space-y-1">
                                <label className="block font-semibold text-zinc-400 uppercase">Durasi Sewa</label>
                                <select
                                    value={durasi}
                                    onChange={(e) => setDurasi(e.target.value)}
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white focus:outline-none focus:border-zinc-500 font-mono"
                                >
                                    <option value="1_minggu">1 Minggu</option>
                                    <option value="1_bulan">1 Bulan</option>
                                    <option value="3_bulan">3 Bulan</option>
                                    <option value="6_bulan">6 Bulan</option>
                                    <option value="1_tahun">1 Tahun</option>
                                </select>
                            </div>

                            <div className="space-y-1">
                                <label className="block font-semibold text-zinc-400 uppercase">Subdomain / Slug</label>
                                <input
                                    type="text"
                                    required
                                    value={clientSlug}
                                    onChange={(e) => setClientSlug(e.target.value)}
                                    placeholder="e.g. tokoku"
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                />
                            </div>

                            <div className="space-y-1">
                                <label className="block font-semibold text-zinc-400 uppercase">Token Bot Telegram</label>
                                <input
                                    type="text"
                                    required
                                    value={telegramToken}
                                    onChange={(e) => setTelegramToken(e.target.value)}
                                    placeholder="e.g. 123456:ABC-DEF..."
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                />
                            </div>

                            <div className="space-y-1">
                                <label className="block font-semibold text-zinc-400 uppercase">Chat ID Telegram</label>
                                <input
                                    type="text"
                                    required
                                    value={telegramChatId}
                                    onChange={(e) => setTelegramChatId(e.target.value)}
                                    placeholder="e.g. 987654321"
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                />
                            </div>

                            <div className="space-y-1">
                                <label className="block font-semibold text-zinc-400 uppercase">Harga (Rupiah)</label>
                                <input
                                    type="number"
                                    value={price}
                                    onChange={(e) => setPrice(e.target.value)}
                                    placeholder="e.g. 150000"
                                    className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                />
                            </div>

                            {serviceKey === 'shopee-spm' && (
                                <>
                                    <div className="space-y-1">
                                        <label className="block font-semibold text-zinc-400 uppercase">URL Tujuan (Target Link)</label>
                                        <input
                                            type="text"
                                            value={targetUrl}
                                            onChange={(e) => setTargetUrl(e.target.value)}
                                            placeholder="Default: https://dd-apps-io.infinityfree.io/SP-20/"
                                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                        />
                                        <span className="text-[10px] text-zinc-600 block">Link di dalam PDF akan otomatis ditambahkan subdomain slug di bagian akhir (contoh: url/slug).</span>
                                    </div>

                                    <div className="space-y-1">
                                        <label className="block font-semibold text-zinc-400 uppercase">Nama File PDF Output</label>
                                        <input
                                            type="text"
                                            value={outputPdf}
                                            onChange={(e) => setOutputPdf(e.target.value)}
                                            placeholder="Default: shopee-16.pdf"
                                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono"
                                        />
                                    </div>
                                </>
                            )}

                            <Button type="submit" variant="primary" loading={loading} className="w-full uppercase font-semibold text-xs tracking-wider">
                                Launch Sandbox Deploy
                            </Button>
                        </form>
                    </Card>
                </div>

                {/* Execution Tracer Timeline */}
                <div className="lg:col-span-8 space-y-6">
                    <Card title="Process Execution Tracer">
                        {leadRef ? (
                            <div className="space-y-6 my-4">
                                <div className="border-l border-zinc-800 ml-3.5 space-y-5">
                                    {STAGES.map((stage) => {
                                        const state = getStageState(stage.key);
                                        return (
                                            <div key={stage.key} className="relative pl-8">
                                                {/* Bullet dot indicator */}
                                                <span className={`absolute left-[-15px] top-0 h-7 w-7 rounded-full border flex items-center justify-center text-[10px] font-bold ${getIndicatorStyle(state)}`}>
                                                    {state === 'success' && '✓'}
                                                    {state === 'error' && '✗'}
                                                    {state === 'pending' && '•'}
                                                    {state === 'waiting' && ' '}
                                                </span>

                                                <div className="space-y-0.5">
                                                    <span className={`text-xs font-semibold uppercase tracking-wider block ${
                                                        state === 'waiting' ? 'text-zinc-600' : 'text-zinc-200'
                                                    }`}>
                                                        {stage.label}
                                                    </span>
                                                    <span className={`text-[11px] block ${
                                                        state === 'waiting' ? 'text-zinc-700' : 'text-zinc-500'
                                                    }`}>
                                                        {stage.desc}
                                                    </span>
                                                </div>
                                            </div>
                                        );
                                    })}
                                </div>

                                {currentStatus?.message && (
                                    <div className="mt-4 p-3 bg-zinc-900/60 border border-zinc-800 rounded text-xs text-zinc-300">
                                        <span className="text-zinc-500 font-bold uppercase block text-[9px] mb-0.5">Engine Status Update</span>
                                        {currentStatus.message}
                                    </div>
                                )}
                            </div>
                        ) : (
                            <div className="p-12 border border-dashed border-zinc-900 text-center text-zinc-600 text-xs font-mono uppercase">
                                Awaiting lead message simulation trigger...
                            </div>
                        )}

                        <div className="space-y-2 pt-2 border-t border-zinc-900">
                            <span className="block text-zinc-500 text-[10px] font-semibold uppercase">Real-Time Trace Log Output</span>
                            <div className="p-4 bg-black border border-zinc-800 rounded h-[180px] overflow-y-auto text-[10px] text-zinc-500 leading-normal scrollbar-none font-mono">
                                <pre className="whitespace-pre-wrap">{logs || 'Awaiting sandbox dispatch to capture logs.'}</pre>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </div>
    );
}
