import React, { useState, useEffect } from 'react';
import ReactDOM from 'react-dom/client';
import Dashboard from './views/Dashboard';
import Templates from './views/Templates';
import Logs from './views/Logs';
import Sandbox from './views/Sandbox';
import Agent from './views/Agent';
import Login from './views/Login';
import { Modal } from './components/Modal';
import { Button } from './components/Button';

// Fetch Interceptor for Admin Passkey authentication
const originalFetch = window.fetch;
window.fetch = async (url, options = {}) => {
    const passkey = localStorage.getItem('admin_passkey') || '';
    
    // Only intercept requests to our local /api/ dashboard endpoints
    if (url.includes('/api/dashboard/')) {
        options.headers = {
            ...options.headers,
            'X-Admin-Passkey': passkey
        };
    }
    
    const response = await originalFetch(url, options);
    
    // Auto-logout on 401 Unauthorized responses
    if (response.status === 401 && url.includes('/api/dashboard/')) {
        localStorage.removeItem('admin_passkey');
        window.dispatchEvent(new Event('admin-logged-out'));
    }
    
    return response;
};

function App() {
    const [isAuthenticated, setIsAuthenticated] = useState(() => {
        return !!localStorage.getItem('admin_passkey');
    });

    const [activeTab, setActiveTab] = useState(() => {
        return localStorage.getItem('active_tab') || 'dashboard';
    });

    const [theme, setTheme] = useState(() => {
        return localStorage.getItem('app_theme') || 'nihilist';
    });

    const [prefilledAgentMessage, setPrefilledAgentMessage] = useState('');

    // Custom Confirm Modal states
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmTitle, setConfirmTitle] = useState('Konfirmasi');
    const [confirmMessage, setConfirmMessage] = useState('');
    const [onConfirm, setOnConfirm] = useState(null);

    const showConfirm = (title, message, callback) => {
        setConfirmTitle(title);
        setConfirmMessage(message);
        setOnConfirm(() => callback);
        setConfirmOpen(true);
    };

    const handleTriggerAgentEdit = (clientSlug) => {
        setPrefilledAgentMessage(`tolong edit file di dalam folder subdomain ${clientSlug}`);
        handleTabChange('agent');
    };

    useEffect(() => {
        const handleLogin = () => setIsAuthenticated(true);
        const handleLogout = () => setIsAuthenticated(false);

        window.addEventListener('admin-logged-in', handleLogin);
        window.addEventListener('admin-logged-out', handleLogout);

        return () => {
            window.removeEventListener('admin-logged-in', handleLogin);
            window.removeEventListener('admin-logged-out', handleLogout);
        };
    }, []);

    useEffect(() => {
        document.documentElement.setAttribute('data-theme', theme);
    }, [theme]);

    // Background polling for new deployments (pending orders) to trigger push notifications
    useEffect(() => {
        if (!isAuthenticated) return;

        // Request permission on load
        if ('Notification' in window && Notification.permission !== 'granted' && Notification.permission !== 'denied') {
            Notification.requestPermission();
        }

        const seenIds = new Set();
        let isInitialLoad = true;

        const checkNewDeployments = async () => {
            try {
                const res = await fetch('/api/dashboard/deployments');
                if (!res.ok) return;
                const deployments = await res.json();

                let hasNewPending = false;
                let newestSlug = '';
                let newestTemplate = '';

                deployments.forEach((d) => {
                    const isPending = d.status === 'pending_payment' || d.status === 'pending';
                    if (isPending) {
                        if (!seenIds.has(d.id)) {
                            seenIds.add(d.id);
                            if (!isInitialLoad) {
                                hasNewPending = true;
                                newestSlug = d.client_slug;
                                newestTemplate = d.service_template?.name || 'Template';
                            }
                        }
                    }
                    seenIds.add(d.id);
                });

                if (hasNewPending && 'Notification' in window && Notification.permission === 'granted') {
                    new Notification("Pesanan Baru Menunggu Approval", {
                        body: `Subdomain: ${newestSlug}\nTemplate: ${newestTemplate}`,
                        icon: '/logo/awbuilder.png',
                        requireInteraction: true
                    });
                    
                    if ('vibrate' in navigator) {
                        navigator.vibrate([200, 100, 200]);
                    }
                }

                isInitialLoad = false;
            } catch (e) {
                console.error("Error polling deployments:", e);
            }
        };

        checkNewDeployments();
        const intervalId = setInterval(checkNewDeployments, 10000);

        return () => clearInterval(intervalId);
    }, [isAuthenticated]);

    const handleThemeChange = (newTheme) => {
        setTheme(newTheme);
        localStorage.setItem('app_theme', newTheme);
    };

    const handleTabChange = (tab) => {
        setActiveTab(tab);
        localStorage.setItem('active_tab', tab);
    };

    const handleLogoutAction = () => {
        showConfirm('Lock Gateway', 'Apakah Anda yakin ingin mengunci admin gateway dan keluar?', () => {
            localStorage.removeItem('admin_passkey');
            window.dispatchEvent(new Event('admin-logged-out'));
        });
    };



    if (!isAuthenticated) {
        return <Login />;
    }

    return (
        <div className="min-h-screen bg-black text-zinc-100 selection:bg-zinc-800 selection:text-white flex flex-col">
            {/* Top Bar for Mobile/Tablet */}
            <header className="lg:hidden flex items-center justify-between px-4 py-4 bg-zinc-950 border-b border-zinc-900 sticky top-0 z-30">
                <div className="flex items-center gap-2.5">
                    <img 
                        src="/logo/awbuilder.png" 
                        alt="AWBuilder" 
                        className="h-7 w-7 object-cover rounded-full"
                    />
                    <div>
                        <span className="font-bold text-zinc-100 tracking-tight text-xs uppercase block font-mono">AUTODEPLOY</span>
                    </div>
                </div>
                <div className="flex items-center gap-3">
                    <select 
                        value={theme} 
                        onChange={(e) => handleThemeChange(e.target.value)}
                        className="bg-zinc-900 border border-zinc-800 rounded px-2.5 py-1 text-[9px] font-mono text-zinc-300 focus:outline-none focus:border-zinc-500 cursor-pointer"
                    >
                        <option value="nihilist">Nihilist</option>
                        <option value="cyberpunk">Cyberpunk</option>
                        <option value="gruvbox-dark">Gruvbox D</option>
                        <option value="gruvbox-light">Gruvbox L</option>
                        <option value="catppuccin">Catppuccin</option>
                        <option value="dracula">Dracula</option>
                        <option value="nord">Nord</option>
                        <option value="one-dark">One Dark</option>
                        <option value="synthwave-84">Synthwave</option>
                        <option value="rose-pine">Rose Pine</option>
                        <option value="tokyo-night">Tokyo Night</option>
                        <option value="everforest">Everforest</option>
                        <option value="solarized-dark">Sol Dark</option>
                        <option value="solarized-light">Sol Light</option>
                        <option value="kanagawa">Kanagawa</option>
                        <option value="ayu-mirage">Ayu Mirage</option>
                        <option value="night-owl">Night Owl</option>
                        <option value="palenight">Palenight</option>
                        <option value="github-dark">GitHub D</option>
                        <option value="monokai-pro">Monokai P</option>
                        <option value="vesper">Vesper</option>
                        <option value="horizon">Horizon</option>
                        <option value="oxocarbon">Oxocarbon</option>
                        <option value="poimandres">Poimandres</option>
                    </select>
                    <div className="flex items-center gap-1.5">
                        <span className="h-1.5 w-1.5 bg-emerald-400 rounded-full animate-pulse"></span>
                        <span className="text-[9px] font-mono text-zinc-500 uppercase tracking-wider">Online</span>
                    </div>
                    <button 
                        onClick={handleLogoutAction}
                        title="Lock Gateway"
                        className="text-zinc-500 hover:text-red-400 transition-colors p-1"
                    >
                        <svg className="w-4.5 h-4.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                        </svg>
                    </button>
                </div>
            </header>

            <div className="flex flex-col lg:flex-row flex-1 min-h-0">
                {/* Sidebar Navigation for Desktop */}
                <aside className="hidden lg:flex lg:fixed lg:top-0 lg:left-0 lg:bottom-0 lg:w-64 bg-zinc-950 border-r border-zinc-900 px-6 py-8 flex-col gap-8 z-30 lg:overflow-y-auto">
                    <div className="flex items-center gap-3">
                        <div className="relative group">
                            <div className="absolute -inset-0.5 bg-gradient-to-r from-zinc-800 to-zinc-650 rounded-full blur opacity-20 group-hover:opacity-50 transition duration-500"></div>
                            <img 
                                src="/logo/awbuilder.png" 
                                alt="AWBuilder" 
                                className="relative h-8.5 w-8.5 object-cover rounded-full border border-zinc-800"
                            />
                        </div>
                        <div>
                            <span className="font-bold text-zinc-100 tracking-tight text-xs uppercase block font-mono">AUTODEPLOY</span>
                            <span className="text-[8px] text-zinc-500 font-mono tracking-widest uppercase block -mt-1">SYSTEM CONTROLLER</span>
                        </div>
                    </div>

                    <nav className="flex flex-col gap-2">
                        <button
                            onClick={() => handleTabChange('dashboard')}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded text-xs font-mono font-semibold uppercase tracking-wider transition-all duration-200 relative group ${
                                activeTab === 'dashboard'
                                    ? 'bg-zinc-900 text-white border border-zinc-800 shadow-[0_2px_8px_rgba(0,0,0,0.5)]'
                                    : 'text-zinc-500 border border-transparent hover:text-zinc-300 hover:bg-zinc-900/30 hover:translate-x-1'
                            }`}
                        >
                            {activeTab === 'dashboard' && (
                                <span className="absolute left-0 top-2 bottom-2 w-0.75 bg-white rounded-r"></span>
                            )}
                            <svg className={`w-4.5 h-4.5 transition-colors ${activeTab === 'dashboard' ? 'text-white' : 'text-zinc-600 group-hover:text-zinc-450'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM13.5 6a2.25 2.25 0 012.25-2.25H18A2.25 2.25 0 0120.25 6v2.25A2.25 2.25 0 0118 10.5h-2.25a2.25 2.25 0 01-2.25-2.25V6zM13.5 15.75a2.25 2.25 0 012.25-2.25H18a2.25 2.25 0 012.25 2.25V18A2.25 2.25 0 0118 20.25h-2.25A2.25 2.25 0 0113.5 18v-2.25z" />
                            </svg>
                            <span>Dashboard</span>
                        </button>
                        <button
                            onClick={() => handleTabChange('templates')}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded text-xs font-mono font-semibold uppercase tracking-wider transition-all duration-200 relative group ${
                                activeTab === 'templates'
                                    ? 'bg-zinc-900 text-white border border-zinc-800 shadow-[0_2px_8px_rgba(0,0,0,0.5)]'
                                    : 'text-zinc-500 border border-transparent hover:text-zinc-300 hover:bg-zinc-900/30 hover:translate-x-1'
                            }`}
                        >
                            {activeTab === 'templates' && (
                                <span className="absolute left-0 top-2 bottom-2 w-0.75 bg-white rounded-r"></span>
                            )}
                            <svg className={`w-4.5 h-4.5 transition-colors ${activeTab === 'templates' ? 'text-white' : 'text-zinc-600 group-hover:text-zinc-450'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                            </svg>
                            <span>Templates</span>
                        </button>
                        <button
                            onClick={() => handleTabChange('logs')}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded text-xs font-mono font-semibold uppercase tracking-wider transition-all duration-200 relative group ${
                                activeTab === 'logs'
                                    ? 'bg-zinc-900 text-white border border-zinc-800 shadow-[0_2px_8px_rgba(0,0,0,0.5)]'
                                    : 'text-zinc-500 border border-transparent hover:text-zinc-300 hover:bg-zinc-900/30 hover:translate-x-1'
                            }`}
                        >
                            {activeTab === 'logs' && (
                                <span className="absolute left-0 top-2 bottom-2 w-0.75 bg-white rounded-r"></span>
                            )}
                            <svg className={`w-4.5 h-4.5 transition-colors ${activeTab === 'logs' ? 'text-white' : 'text-zinc-600 group-hover:text-zinc-450'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5" />
                            </svg>
                            <span>Audit Logs</span>
                        </button>
                        <button
                            onClick={() => handleTabChange('sandbox')}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded text-xs font-mono font-semibold uppercase tracking-wider transition-all duration-200 relative group ${
                                activeTab === 'sandbox'
                                    ? 'bg-zinc-900 text-white border border-zinc-800 shadow-[0_2px_8px_rgba(0,0,0,0.5)]'
                                    : 'text-zinc-500 border border-transparent hover:text-zinc-300 hover:bg-zinc-900/30 hover:translate-x-1'
                            }`}
                        >
                            {activeTab === 'sandbox' && (
                                <span className="absolute left-0 top-2 bottom-2 w-0.75 bg-white rounded-r"></span>
                            )}
                            <svg className={`w-4.5 h-4.5 transition-colors ${activeTab === 'sandbox' ? 'text-white' : 'text-zinc-600 group-hover:text-zinc-450'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                            </svg>
                            <span>Sandbox</span>
                        </button>
                        <button
                            onClick={() => handleTabChange('agent')}
                            className={`flex items-center gap-3 px-3.5 py-2.5 rounded text-xs font-mono font-semibold uppercase tracking-wider transition-all duration-200 relative group ${
                                activeTab === 'agent'
                                    ? 'bg-zinc-900 text-white border border-zinc-800 shadow-[0_2px_8px_rgba(0,0,0,0.5)]'
                                    : 'text-zinc-500 border border-transparent hover:text-zinc-300 hover:bg-zinc-900/30 hover:translate-x-1'
                            }`}
                        >
                            {activeTab === 'agent' && (
                                <span className="absolute left-0 top-2 bottom-2 w-0.75 bg-white rounded-r"></span>
                            )}
                            <svg className={`w-4.5 h-4.5 transition-colors ${activeTab === 'agent' ? 'text-white' : 'text-zinc-600 group-hover:text-zinc-450'}`} fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 3v1.5M4.5 8.25H3m1.5 7.5H3m15-7.5h1.5m-1.5 7.5h1.5m-10.5-12h7.5A2.25 2.25 0 0118 4.5v15a2.25 2.25 0 01-2.25 2.25H8.25A2.25 2.25 0 016 19.5v-15A2.25 2.25 0 018.25 2.25zM13.5 9h-3a.75.75 0 00-.75.75v3c0 .414.336.75.75.75h3a.75.75 0 00.75-.75v-3a.75.75 0 00-.75-.75z" />
                            </svg>
                            <span>AI Worker</span>
                        </button>

                        <button
                            onClick={handleLogoutAction}
                            className="flex items-center gap-3 px-3.5 py-2.5 rounded text-xs font-mono font-semibold uppercase tracking-wider text-red-500 hover:text-red-400 border border-transparent hover:bg-red-950/15 hover:translate-x-1 transition-all duration-200 mt-4 w-full text-left bg-zinc-950/20"
                        >
                            <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                                <path strokeLinecap="round" strokeLinejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                            </svg>
                            <span>Lock Gateway</span>
                        </button>
                    </nav>

                    <div className="mt-auto pt-6 border-t border-zinc-900 flex flex-col gap-3.5 font-mono">
                        <div className="flex flex-col gap-1.5">
                            <span className="text-zinc-500 text-[9px] font-bold tracking-wider uppercase">Active Theme</span>
                            <select 
                                value={theme} 
                                onChange={(e) => handleThemeChange(e.target.value)}
                                className="w-full bg-zinc-900 border border-zinc-800 rounded px-2.5 py-1.5 text-[10px] font-mono text-zinc-350 focus:outline-none focus:border-zinc-500 cursor-pointer"
                            >
                                <option value="nihilist">Nihilist B&W</option>
                                <option value="cyberpunk">Cyberpunk Neon</option>
                                <option value="gruvbox-dark">Gruvbox Dark</option>
                                <option value="gruvbox-light">Gruvbox Light</option>
                                <option value="catppuccin">Catppuccin Pastel</option>
                                <option value="dracula">Dracula Dark</option>
                                <option value="nord">Nord Arctic</option>
                                <option value="one-dark">One Dark Classic</option>
                                <option value="synthwave-84">Synthwave '84</option>
                                <option value="rose-pine">Rosé Pine Cozy</option>
                                <option value="tokyo-night">Tokyo Night Neon</option>
                                <option value="everforest">Everforest Organic</option>
                                <option value="solarized-dark">Solarized Dark</option>
                                <option value="solarized-light">Solarized Light</option>
                                <option value="kanagawa">Kanagawa Wave</option>
                                <option value="ayu-mirage">Ayu Mirage Dark</option>
                                <option value="night-owl">Night Owl Deep</option>
                                <option value="palenight">Palenight Cozy</option>
                                <option value="github-dark">GitHub Dark Mode</option>
                                <option value="monokai-pro">Monokai Pro Dark</option>
                                <option value="vesper">Vesper Minimal</option>
                                <option value="horizon">Horizon Eclipse</option>
                                <option value="oxocarbon">Oxocarbon Carbon</option>
                                <option value="poimandres">Poimandres Mint</option>
                            </select>
                        </div>

                        <div className="flex flex-col gap-1">
                            <span className="text-zinc-650 text-[9px] font-bold tracking-wider uppercase">Server Status</span>
                            <span className="text-[11px] font-semibold text-zinc-400 flex items-center gap-2">
                                <span className="h-1.5 w-1.5 bg-emerald-400 rounded-full animate-pulse shadow-[0_0_8px_var(--accent-success)]"></span>
                                Active Audit Engine
                            </span>
                        </div>
                    </div>
                </aside>

                {/* Bottom Nav Bar for Mobile/Tablet */}
                <nav className="lg:hidden fixed bottom-0 left-0 right-0 z-40 bg-zinc-950/95 backdrop-blur-md border-t border-zinc-900 flex justify-around py-3 px-2">
                    <button
                        onClick={() => handleTabChange('dashboard')}
                        className={`flex flex-col items-center gap-1.5 text-[9px] font-mono font-semibold uppercase tracking-wider transition-colors ${
                            activeTab === 'dashboard' ? 'text-white' : 'text-zinc-650 hover:text-zinc-400'
                        }`}
                    >
                        <svg className="w-4.5 h-4.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 6A2.25 2.25 0 016 3.75h2.25A2.25 2.25 0 0110.5 6v2.25a2.25 2.25 0 01-2.25 2.25H6a2.25 2.25 0 01-2.25-2.25V6zM3.75 15.75A2.25 2.25 0 016 13.5h2.25a2.25 2.25 0 012.25 2.25V18a2.25 2.25 0 01-2.25 2.25H6A2.25 2.25 0 013.75 18v-2.25z" />
                        </svg>
                        <span>Status</span>
                    </button>
                    <button
                        onClick={() => handleTabChange('templates')}
                        className={`flex flex-col items-center gap-1.5 text-[9px] font-mono font-semibold uppercase tracking-wider transition-colors ${
                            activeTab === 'templates' ? 'text-white' : 'text-zinc-650 hover:text-zinc-400'
                        }`}
                    >
                        <svg className="w-4.5 h-4.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M21 7.5l-9-5.25L3 7.5m18 0l-9 5.25m9-5.25v9l-9 5.25M3 7.5l9 5.25M3 7.5v9l9 5.25m0-9v9" />
                        </svg>
                        <span>Templates</span>
                    </button>
                    <button
                        onClick={() => handleTabChange('logs')}
                        className={`flex flex-col items-center gap-1.5 text-[9px] font-mono font-semibold uppercase tracking-wider transition-colors ${
                            activeTab === 'logs' ? 'text-white' : 'text-zinc-650 hover:text-zinc-400'
                        }`}
                    >
                        <svg className="w-4.5 h-4.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M17.25 6.75L22.5 12l-5.25 5.25m-10.5 0L1.5 12l5.25-5.25m7.5-3l-4.5 16.5" />
                        </svg>
                        <span>Logs</span>
                    </button>
                    <button
                        onClick={() => handleTabChange('sandbox')}
                        className={`flex flex-col items-center gap-1.5 text-[9px] font-mono font-semibold uppercase tracking-wider transition-colors ${
                            activeTab === 'sandbox' ? 'text-white' : 'text-zinc-650 hover:text-zinc-400'
                        }`}
                    >
                        <svg className="w-4.5 h-4.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9.75 9.75l4.5 4.5m0-4.5l-4.5 4.5M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                        <span>Sandbox</span>
                    </button>
                    <button
                        onClick={() => handleTabChange('agent')}
                        className={`flex flex-col items-center gap-1.5 text-[9px] font-mono font-semibold uppercase tracking-wider transition-colors ${
                            activeTab === 'agent' ? 'text-white' : 'text-zinc-650 hover:text-zinc-400'
                        }`}
                    >
                        <svg className="w-4.5 h-4.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M8.25 3v1.5M4.5 8.25H3m1.5 7.5H3m15-7.5h1.5m-1.5 7.5h1.5m-10.5-12h7.5A2.25 2.25 0 0118 4.5v15a2.25 2.25 0 01-2.25 2.25H8.25A2.25 2.25 0 016 19.5v-15A2.25 2.25 0 018.25 2.25zM13.5 9h-3a.75.75 0 00-.75.75v3c0 .414.336.75.75.75h3a.75.75 0 00.75-.75v-3a.75.75 0 00-.75-.75z" />
                        </svg>
                        <span>Agent</span>
                    </button>
                </nav>

                {/* Main Content Area Wrapper to offset fixed sidebar */}
                <div className="flex-1 flex flex-col lg:pl-64 min-w-0">
                    <main className={`flex-1 bg-black max-w-7xl w-full ${
                        activeTab === 'agent'
                            ? 'h-[calc(100dvh-160px)] lg:h-auto overflow-hidden lg:overflow-y-auto p-4 md:p-8 lg:px-12 lg:py-10 lg:pb-10'
                            : 'overflow-y-auto px-4 py-6 md:px-8 md:py-8 lg:px-12 lg:py-10 pb-24 lg:pb-10'
                    }`}>
                        <div className={activeTab === 'dashboard' ? '' : 'hidden'}>
                            <Dashboard onTriggerAgentEdit={handleTriggerAgentEdit} />
                        </div>
                        <div className={activeTab === 'templates' ? '' : 'hidden'}>
                            <Templates />
                        </div>
                        <div className={activeTab === 'logs' ? '' : 'hidden'}>
                            <Logs />
                        </div>
                        <div className={activeTab === 'sandbox' ? '' : 'hidden'}>
                            <Sandbox />
                        </div>
                        <div className={activeTab === 'agent' ? 'h-full' : 'hidden'}>
                            <Agent 
                                prefilledMessage={prefilledAgentMessage} 
                                clearPrefilledMessage={() => setPrefilledAgentMessage('')} 
                            />
                        </div>
                    </main>
                </div>
            </div>

            {/* Custom Confirm Modal */}
            <Modal
                isOpen={confirmOpen}
                onClose={() => setConfirmOpen(false)}
                title={confirmTitle}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setConfirmOpen(false)}>Batal</Button>
                        <Button variant="danger" onClick={() => {
                            setConfirmOpen(false);
                            if (onConfirm) onConfirm();
                        }}>OK</Button>
                    </>
                }
            >
                <div className="py-2 text-zinc-300 font-mono text-xs">
                    {confirmMessage}
                </div>
            </Modal>
        </div>
    );
}

const root = ReactDOM.createRoot(document.getElementById('app'));
root.render(<App />);
