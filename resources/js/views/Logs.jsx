import React, { useState, useEffect, useRef } from 'react';
import { Card } from '../components/Card';
import { Button } from '../components/Button';

export default function Logs() {
    const [logs, setLogs] = useState([]);
    const [searchQuery, setSearchQuery] = useState('');
    const [autoRefresh, setAutoRefresh] = useState(true);
    const [copied, setCopied] = useState(false);
    const terminalRef = useRef(null);

    const fetchLogs = async () => {
        try {
            const res = await fetch('/api/dashboard/logs');
            const data = await res.json();
            // Split into lines for search filtering
            const rawLogs = data.logs || '';
            setLogs(rawLogs.split('\n'));
        } catch (e) {
            setLogs(['[ERROR] Failed to fetch logs from server.']);
        }
    };

    useEffect(() => {
        fetchLogs();
    }, []);

    // Polling interval
    useEffect(() => {
        if (!autoRefresh) return;
        const interval = setInterval(fetchLogs, 3000);
        return () => clearInterval(interval);
    }, [autoRefresh]);

    // Auto-scroll to bottom
    useEffect(() => {
        if (terminalRef.current && autoRefresh) {
            terminalRef.current.scrollTop = terminalRef.current.scrollHeight;
        }
    }, [logs, autoRefresh]);

    const handleCopy = () => {
        const fullLogs = logs.join('\n');
        navigator.clipboard.writeText(fullLogs);
        setCopied(true);
        setTimeout(() => setCopied(false), 2000);
    };

    // Filter logs based on search query
    const filteredLogs = logs.filter(line => 
        line.toLowerCase().includes(searchQuery.toLowerCase())
    );

    return (
        <div className="space-y-8 font-mono">
            <div className="flex flex-col md:flex-row md:items-center justify-between gap-4">
                <div>
                    <h1 className="text-xl font-bold text-white tracking-tight uppercase">Audit Logs</h1>
                    <p className="text-zinc-500 text-xs mt-1">Trace log trail of deployments and LLM operations.</p>
                </div>
                <div className="flex flex-wrap items-center gap-3 text-xs font-semibold uppercase tracking-wider text-zinc-400">
                    <label className="inline-flex items-center gap-2 cursor-pointer select-none">
                        <input
                            type="checkbox"
                            checked={autoRefresh}
                            onChange={(e) => setAutoRefresh(e.target.checked)}
                            className="w-3.5 h-3.5 bg-zinc-900 border-zinc-800 text-white rounded focus:ring-zinc-500 focus:ring-offset-0"
                        />
                        <span>Auto Scroll/Refresh</span>
                    </label>
                    <Button onClick={handleCopy} variant="secondary" size="sm">
                        {copied ? 'Copied' : 'Copy'}
                    </Button>
                    <Button onClick={fetchLogs} variant="secondary" size="sm">
                        Refresh
                    </Button>
                </div>
            </div>

            {/* Filter Input */}
            <div className="relative">
                <span className="absolute inset-y-0 left-0 pl-3 flex items-center text-zinc-600">
                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M21 21l-6-6m2-5a7 7 0 11-14 0 7 7 0 0114 0z" />
                    </svg>
                </span>
                <input
                    type="text"
                    placeholder="FILTER LOGS BY KEYWORD OR SLUG..."
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    className="w-full bg-zinc-950 border border-zinc-800 rounded px-3 py-2 pl-9 text-xs text-white placeholder-zinc-700 focus:outline-none focus:border-zinc-500 font-mono tracking-wider"
                />
            </div>

            <Card className="p-0 border-zinc-800 overflow-hidden bg-black shadow-none relative">
                {/* Window header */}
                <div className="bg-zinc-950 border-b border-zinc-800 px-5 py-3.5 flex items-center justify-between">
                    <div className="flex items-center gap-2">
                        <span className="w-2.5 h-2.5 rounded-full bg-zinc-800"></span>
                        <span className="w-2.5 h-2.5 rounded-full bg-zinc-800"></span>
                        <span className="w-2.5 h-2.5 rounded-full bg-zinc-800"></span>
                        <span className="text-[10px] text-zinc-500 ml-2">deploy-audit.log</span>
                    </div>
                    {searchQuery && (
                        <span className="text-[9px] text-amber-500 font-bold bg-amber-950/20 border border-amber-900/30 px-2 py-0.5 rounded uppercase">
                            Filtered: {filteredLogs.length} / {logs.length} Lines
                        </span>
                    )}
                </div>

                {/* Terminal screen */}
                <div 
                    ref={terminalRef}
                    className="p-6 h-[480px] overflow-y-auto text-xs text-zinc-300 leading-relaxed select-text scrollbar-none bg-black"
                >
                    {filteredLogs.length === 0 ? (
                        <div className="text-zinc-700 py-10 text-center uppercase tracking-wider">
                            No logs match the current search query.
                        </div>
                    ) : (
                        <pre className="whitespace-pre-wrap break-all font-mono">
                            {filteredLogs.join('\n')}
                        </pre>
                    )}
                </div>
            </Card>
        </div>
    );
}
