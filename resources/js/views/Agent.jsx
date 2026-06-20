import React, { useState, useEffect, useRef } from 'react';
import { Card } from '../components/Card';
import { Button } from '../components/Button';

export default function Agent() {
    const [config, setConfig] = useState(null);
    const [systemPrompt, setSystemPrompt] = useState('');
    const [userMessage, setUserMessage] = useState('');
    const [passkey, setPasskey] = useState(localStorage.getItem('agent_passkey') || '');
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
        
        // Add to history
        setChatHistory(prev => [...prev, { role: 'user', content: currentMsg }]);
        setLoading(true);

        try {
            const res = await fetch('/api/dashboard/agent/chat', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    system_prompt: systemPrompt,
                    message: currentMsg,
                    passkey: passkey
                })
            });
            const data = await res.json();

            if (res.status === 403) {
                setChatHistory(prev => [...prev, { role: 'assistant', content: `Error: ${data.error || 'Akses ditolak.'}`, isError: true }]);
            } else if (data.success) {
                setChatHistory(prev => [...prev, { role: 'assistant', content: data.response }]);
            } else {
                setChatHistory(prev => [...prev, { role: 'assistant', content: `Error: ${data.error || 'Failed to get response.'}`, isError: true }]);
            }
        } catch (err) {
            setChatHistory(prev => [...prev, { role: 'assistant', content: 'Error: Failed to connect to server API.', isError: true }]);
        } finally {
            setLoading(false);
        }
    };

    const handleClearChat = () => {
        setChatHistory([
            { role: 'assistant', content: 'Agent Workspace cleared. Send instructions or queries to test model behavior.' }
        ]);
    };

    return (
        <div className="space-y-8 font-mono text-xs">
            <div>
                <h1 className="text-xl font-bold text-white tracking-tight uppercase">Agent Control Center</h1>
                <p className="text-zinc-500 text-xs mt-1">Configure system instructions and communicate directly with the AI Worker model.</p>
            </div>

            {/* Model Metadata Status Card */}
            {config && (
                <div className="grid grid-cols-1 md:grid-cols-4 gap-4 border border-zinc-800 bg-zinc-950/60 p-4 rounded-lg">
                    <div>
                        <span className="text-zinc-500 block uppercase font-semibold text-[10px]">Active Worker Model</span>
                        <span className="text-white font-bold block mt-0.5 text-sm tracking-tight">{config.model}</span>
                    </div>
                    <div>
                        <span className="text-zinc-500 block uppercase font-semibold text-[10px]">Gateway Connection</span>
                        <span className="text-zinc-300 block mt-0.5 break-all">{config.api_url}</span>
                    </div>
                    <div className="flex flex-col justify-center">
                        <span className="text-zinc-500 block uppercase font-semibold text-[10px] mb-1">Status</span>
                        <div className="flex items-center gap-2">
                            <span className={`h-2 w-2 rounded-full ${config.has_api_key ? 'bg-white' : 'bg-red-500'} animate-pulse`}></span>
                            <span className="text-zinc-300 font-bold uppercase">
                                {config.has_api_key ? 'Connected' : 'Disconnected'}
                            </span>
                        </div>
                    </div>
                    <div className="flex flex-col justify-center">
                        <span className="text-zinc-500 block uppercase font-semibold text-[10px] mb-1">Passkey Akses (6 Digit)</span>
                        <input
                            type="password"
                            maxLength="6"
                            placeholder="------"
                            value={passkey}
                            onChange={(e) => {
                                const val = e.target.value.replace(/\D/g, ''); // only digits
                                setPasskey(val);
                                localStorage.setItem('agent_passkey', val);
                            }}
                            className="bg-zinc-900 border border-zinc-800 rounded px-2.5 py-1 text-white placeholder-zinc-700 w-24 text-center font-bold tracking-widest text-xs focus:outline-none focus:border-zinc-500"
                        />
                    </div>
                </div>
            )}

            <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                {/* Chat Interactive Playground (Full Width) */}
                <div className="lg:col-span-12 flex flex-col h-full">
                    <Card 
                        title="AI Worker Workspace"
                        action={
                            <Button size="sm" variant="secondary" onClick={handleClearChat} disabled={passkey.length < 6}>
                                Clear History
                            </Button>
                        }
                        className="flex flex-col h-[600px]"
                    >
                        {/* Conversation Box */}
                        {passkey.length < 6 ? (
                            <div className="flex-1 flex flex-col items-center justify-center border border-dashed border-zinc-900 rounded-lg p-8 text-center space-y-3 my-4">
                                <svg className="w-8 h-8 text-zinc-650 animate-bounce" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                                    <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                                <span className="text-zinc-500 uppercase font-bold tracking-wider text-[10px]">Akses Terkunci</span>
                                <p className="text-zinc-600 max-w-xs leading-normal">
                                    Masukkan 6-digit passkey pada panel status di atas untuk mengaktifkan obrolan dengan AI Worker.
                                </p>
                            </div>
                        ) : (
                            <div className="flex-1 overflow-y-auto pr-2 space-y-4 mb-4 h-[440px] min-h-[440px] max-h-[440px] scrollbar-thin scrollbar-thumb-zinc-800 scrollbar-track-transparent">
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
                </div>
            </div>
        </div>
    );
}
