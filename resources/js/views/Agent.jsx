import React, { useState, useEffect, useRef } from 'react';
import { Card } from '../components/Card';
import { Button } from '../components/Button';
import { Modal } from '../components/Modal';

function tryParseDeployJson(text) {
    if (!text) return null;
    let cleanText = text.trim();
    if (cleanText.startsWith('```json')) {
        cleanText = cleanText.substring(7);
    } else if (cleanText.startsWith('```')) {
        cleanText = cleanText.substring(3);
    }
    if (cleanText.endsWith('```')) {
        cleanText = cleanText.substring(0, cleanText.length - 3);
    }
    cleanText = cleanText.trim();

    try {
        const parsed = JSON.parse(cleanText);
        if (parsed && (parsed.status === 'ready_to_deploy' || parsed.ready_to_deploy === true)) {
            return parsed;
        }
    } catch (e) {
        // Ignore parsing error
    }
    return null;
}

export default function Agent() {
    const [config, setConfig] = useState(null);
    const [systemPrompt, setSystemPrompt] = useState('');
    const [userMessage, setUserMessage] = useState('');
    const [passkey, setPasskey] = useState(localStorage.getItem('agent_passkey') || '');
    const [lockModalOpen, setLockModalOpen] = useState(false);
    const [tempPasskey, setTempPasskey] = useState('');
    const [chatHistory, setChatHistory] = useState([
        { role: 'assistant', content: 'Agent Workspace online. Send instructions or queries to test model behavior.' }
    ]);
    const [loading, setLoading] = useState(false);
    const chatEndRef = useRef(null);

    // Fetch model config on mount
    useEffect(() => {
        fetchAgentConfig();
    }, []);

    // Scroll to bottom of chat
    useEffect(() => {
        chatEndRef.current?.scrollIntoView({ behavior: 'smooth' });
    }, [chatHistory]);

    const fetchAgentConfig = async () => {
        try {
            const res = await fetch('/api/dashboard/agent/config');
            const data = await res.json();
            setConfig(data);
            setSystemPrompt(data.default_system_prompt || '');
        } catch (e) {
            console.error('Failed to fetch agent config', e);
        }
    };

    const handleResetPrompt = () => {
        if (config) {
            setSystemPrompt(config.default_system_prompt || '');
        }
    };

    const handleSend = async (e) => {
        e.preventDefault();
        if (!userMessage.trim() || loading || passkey.length < 6) return;

        const currentMsg = userMessage;
        setUserMessage('');
        
        // Construct the updated history array including user's new message
        const updatedHistory = [...chatHistory, { role: 'user', content: currentMsg }];
        setChatHistory(updatedHistory);
        setLoading(true);

        try {
            const res = await fetch('/api/dashboard/agent/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    messages: updatedHistory.map(h => ({ role: h.role, content: h.content })),
                    passkey: passkey
                })
            });
            const data = await res.json();

            if (res.status === 403) {
                setChatHistory(prev => [...prev, { role: 'assistant', content: `Error: ${data.error || 'Akses ditolak.'}`, isError: true }]);
                setLoading(false);
            } else if (data.success) {
                const deployData = tryParseDeployJson(data.response);
                if (deployData) {
                    // Inject deployment loading status inline to the chat log
                    const deployMsg = { 
                        role: 'assistant', 
                        content: `⚡ AI Worker: Parameter lengkap terdeteksi. Memulai proses deployment otomatis...\n\n- Blueprint Key: ${deployData.service_key}\n- Durasi Sewa: ${deployData.durasi}\n- Subdomain / Slug: ${deployData.client_slug_request}\n- Target Telegram Chat ID: ${deployData.telegram_chat_id}\n\nSedang mereplikasi filesystem template dan mengeksekusi script deploy.sh pada VPS Laravel worker...`,
                        isDeploying: true
                    };
                    setChatHistory(prev => [...prev, deployMsg]);
                    setLoading(false);

                    try {
                        const deployRes = await fetch('/api/dashboard/agent/deploy', {
                            method: 'POST',
                            headers: { 'Content-Type': 'application/json' },
                            body: JSON.stringify({
                                service_key: deployData.service_key,
                                durasi: deployData.durasi,
                                client_slug_request: deployData.client_slug_request,
                                telegram_token: deployData.telegram_token,
                                telegram_chat_id: deployData.telegram_chat_id,
                                price: deployData.price
                            })
                        });
                        const deployResult = await deployRes.json();
                        
                        if (deployResult.success) {
                            setChatHistory(prev => [
                                ...prev.map(m => m.isDeploying ? {
                                    role: 'assistant',
                                    content: `🎉 Deployment sukses!\n\nInstansi untuk client '${deployData.client_slug_request}' berhasil dibuat.\nSemua script konfigurasi dijalankan dengan sukses dan reverse proxy telah terdaftar.`,
                                    url: deployResult.url,
                                    deployment: deployResult.deployment
                                } : m)
                            ]);
                        } else {
                            setChatHistory(prev => [
                                ...prev.map(m => m.isDeploying ? {
                                    role: 'assistant',
                                    content: `❌ Deployment Gagal: ${deployResult.error || 'Gagal mengeksekusi deploy.sh pada VPS.'}`,
                                    isError: true
                                } : m)
                            ]);
                        }
                    } catch (deployErr) {
                        setChatHistory(prev => [
                            ...prev.map(m => m.isDeploying ? {
                                role: 'assistant',
                                content: `❌ Gangguan Koneksi: Gagal berkomunikasi dengan API deployment server.`,
                                isError: true
                            } : m)
                        ]);
                    }
                } else {
                    setChatHistory(prev => [...prev, { role: 'assistant', content: data.response }]);
                    setLoading(false);
                }
            } else {
                setChatHistory(prev => [...prev, { role: 'assistant', content: `Error: ${data.error || 'Gagal mendapatkan respon AI.'}`, isError: true }]);
                setLoading(false);
            }
        } catch (err) {
            setChatHistory(prev => [...prev, { role: 'assistant', content: 'Error: Gagal terhubung ke API server.', isError: true }]);
            setLoading(false);
        }
    };

    const handleClearChat = () => {
        setChatHistory([
            { role: 'assistant', content: 'Agent Workspace cleared. Send instructions or queries to test model behavior.' }
        ]);
    };

    return (
        <div className="font-mono text-xs">
            <Card 
                title="AI Worker Workspace"
                action={
                    <div className="flex gap-2">
                        <Button size="sm" variant="secondary" onClick={() => {
                            setTempPasskey(passkey);
                            setLockModalOpen(true);
                        }}>
                            Passkey
                        </Button>
                        <Button size="sm" variant="secondary" onClick={handleClearChat} disabled={passkey.length < 6}>
                            Clear History
                        </Button>
                    </div>
                }
                className="flex flex-col h-[calc(100vh-140px)] lg:h-[650px]"
            >
                        {/* Conversation Box */}
                        {passkey.length < 6 ? (
                            <div className="flex-1 flex flex-col items-center justify-center border border-dashed border-zinc-900 rounded-lg p-8 text-center space-y-4 my-4">
                                <svg className="w-8 h-8 text-zinc-650 animate-bounce" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                <div className="space-y-1">
                                    <span className="text-zinc-500 uppercase font-bold tracking-wider text-[10px]">Akses Terkunci</span>
                                    <p className="text-zinc-600 max-w-xs leading-normal">
                                        Masukkan 6-digit passkey untuk mengaktifkan obrolan dengan AI Worker.
                                    </p>
                                </div>
                                <Button 
                                    variant="primary" 
                                    onClick={() => {
                                        setTempPasskey('');
                                        setLockModalOpen(true);
                                    }}
                                    className="uppercase font-semibold tracking-wider text-xs px-6 py-2.5"
                                >
                                    Unlock Workspace
                                </Button>
                            </div>
                        ) : (
                             <div className="flex-1 overflow-y-auto pr-2 space-y-4 mb-4 scrollbar-thin scrollbar-thumb-zinc-800 scrollbar-track-transparent">
                                {chatHistory.map((msg, index) => (
                                    <div 
                                        key={index} 
                                        className={`flex flex-col max-w-[85%] ${msg.role === 'user' ? 'ml-auto items-end' : 'items-start'}`}
                                    >
                                        <span className="text-[9px] text-zinc-650 font-bold uppercase tracking-wider mb-1">
                                            {msg.role === 'user' ? 'You' : 'AI Worker'}
                                        </span>
                                        <div className={`p-3.5 rounded-lg border font-mono leading-relaxed text-[11px] ${
                                            msg.role === 'user' 
                                                ? 'bg-zinc-900 border-zinc-800 text-white' 
                                                : msg.isError 
                                                    ? 'bg-black border-red-950 text-red-400' 
                                                    : 'bg-zinc-950 border-zinc-900 text-zinc-300'
                                        }`}>
                                            <p className="whitespace-pre-wrap">{msg.content}</p>

                                            {msg.isDeploying && (
                                                <div className="mt-3 flex items-center gap-2 border-t border-zinc-900 pt-3">
                                                    <span className="animate-spin rounded-full h-3.5 w-3.5 border-t border-b border-white"></span>
                                                    <span className="text-[10px] text-zinc-450 uppercase font-bold tracking-wider animate-pulse">
                                                        VPS executing deploy.sh...
                                                    </span>
                                                </div>
                                            )}

                                            {msg.url && (
                                                <div className="mt-3.5 p-3 bg-zinc-900 border border-zinc-800 rounded-lg flex flex-col gap-2 max-w-[280px] sm:max-w-xs md:max-w-sm">
                                                    <div className="flex items-center gap-2">
                                                        <span className="h-1.5 w-1.5 rounded-full bg-white animate-pulse"></span>
                                                        <span className="text-[9px] text-white font-bold uppercase tracking-widest">Instance Live</span>
                                                    </div>
                                                    <a 
                                                        href={msg.url} 
                                                        target="_blank" 
                                                        rel="noopener noreferrer" 
                                                        className="inline-flex items-center justify-between bg-white text-black font-bold uppercase px-2.5 py-1.5 rounded hover:bg-zinc-200 transition-colors mt-1 font-mono text-[9px]"
                                                    >
                                                        <span className="truncate mr-1.5">{msg.url}</span>
                                                        <svg className="w-3 h-3 flex-shrink-0" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                                                            <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                        </svg>
                                                    </a>
                                                </div>
                                            )}
                                        </div>
                                    </div>
                                ))}
                                {loading && (
                                    <div className="flex flex-col max-w-[80%] items-start">
                                        <span className="text-[9px] text-zinc-650 font-bold uppercase tracking-wider mb-1">AI Worker</span>
                                        <div className="p-3 bg-zinc-950 border border-zinc-900 rounded-lg text-zinc-500 animate-pulse">
                                            Thinking and streaming response...
                                        </div>
                                    </div>
                                )}
                                <div ref={chatEndRef} />
                            </div>
                        )}

                        {/* Message Input Form */}
                        <form onSubmit={handleSend} className="mt-auto border-t border-zinc-900 pt-4 flex gap-2">
                            <input
                                type="text"
                                value={userMessage}
                                onChange={(e) => setUserMessage(e.target.value)}
                                placeholder={passkey.length < 6 ? "Masukkan passkey terlebih dahulu..." : "Tanya apa saja mengenai sistem auto-deployment atau berikan instruksi..."}
                                disabled={loading || passkey.length < 6}
                                className="flex-1 bg-zinc-900 border border-zinc-855 hover:border-zinc-800 focus:border-zinc-600 rounded-lg px-4 py-3 text-white focus:outline-none placeholder-zinc-700 text-xs font-mono disabled:opacity-40"
                            />
                            <Button 
                                type="submit" 
                                variant="primary" 
                                disabled={loading || !userMessage.trim() || passkey.length < 6}
                                className="uppercase font-semibold text-xs tracking-wider px-5"
                            >
                                Send
                            </Button>
                        </form>
                    </Card>

            {/* Passkey Input Modal */}
            <Modal
                isOpen={lockModalOpen}
                onClose={() => setLockModalOpen(false)}
                title="AI Worker Authentication"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setLockModalOpen(false)}>
                            Batal
                        </Button>
                        <Button 
                            variant="primary" 
                            disabled={tempPasskey.length < 6}
                            onClick={() => {
                                setPasskey(tempPasskey);
                                localStorage.setItem('agent_passkey', tempPasskey);
                                setLockModalOpen(false);
                            }}
                        >
                            Simpan & Unlock
                        </Button>
                    </>
                }
            >
                <div className="space-y-4 text-center font-mono text-xs">
                    <p className="text-zinc-400 leading-normal uppercase text-[10px]">
                        Masukkan 6-digit passkey khusus AI Worker Anda
                    </p>
                    <div className="max-w-xs mx-auto">
                        <input
                            type="password"
                            maxLength="6"
                            placeholder="------"
                            value={tempPasskey}
                            onChange={(e) => {
                                const val = e.target.value.replace(/\D/g, ''); // only digits
                                setTempPasskey(val);
                            }}
                            className="w-full bg-zinc-900 border border-zinc-800 rounded p-3 text-white text-center font-bold tracking-[1.5em] text-lg placeholder-zinc-700 focus:outline-none focus:border-zinc-500"
                        />
                    </div>
                </div>
            </Modal>
        </div>
    );
}
