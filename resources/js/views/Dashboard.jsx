import React, { useState, useEffect } from 'react';
import { StatCard, Card } from '../components/Card';
import { Badge } from '../components/Badge';
import { Button } from '../components/Button';
import { Modal } from '../components/Modal';
import { Alert } from '../components/Alert';

const getDeploymentUrl = (clientSlug) => {
    const hostname = window.location.hostname;
    const baseDomain = hostname.includes('mockbuild.shop') ? hostname : 'mockbuild.shop';
    return `http://${clientSlug}.${baseDomain}`;
};

export default function Dashboard() {
    const [stats, setStats] = useState(null);
    const [deployments, setDeployments] = useState([]);
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(null);
    const [banner, setBanner] = useState(null); // { type: 'success'|'error', text }
    
    // Modal states
    const [extendModalOpen, setExtendModalOpen] = useState(false);
    const [selectedDeployment, setSelectedDeployment] = useState(null);
    const [extendDuration, setExtendDuration] = useState('1_minggu');

    const showBanner = (type, text) => {
        setBanner({ type, text });
        setTimeout(() => setBanner(null), 5000);
    };

    const fetchStats = async () => {
        try {
            const res = await fetch('/api/dashboard/stats');
            const data = await res.json();
            setStats(data);
        } catch (e) {
            console.error('Error fetching stats', e);
        }
    };

    const fetchDeployments = async () => {
        try {
            const res = await fetch('/api/dashboard/deployments');
            const data = await res.json();
            setDeployments(data);
        } catch (e) {
            console.error('Error fetching deployments', e);
        }
    };

    const loadData = async () => {
        setLoading(true);
        await Promise.all([fetchStats(), fetchDeployments()]);
        setLoading(false);
    };

    useEffect(() => {
        loadData();
    }, []);

    const handleTeardown = async (id) => {
        if (!confirm('Are you sure you want to teardown and archive this deployment?')) return;
        setActionLoading(id);
        try {
            const res = await fetch(`/api/dashboard/deployments/${id}/teardown`, { method: 'POST' });
            const data = await res.json();
            if (data.error) {
                showBanner('error', data.error);
            } else {
                showBanner('success', 'Deployment torn down and archived successfully.');
                loadData();
            }
        } catch (e) {
            showBanner('error', 'Teardown connection error.');
        } finally {
            setActionLoading(null);
        }
    };

    const handleRetry = async (id) => {
        setActionLoading(id);
        try {
            const res = await fetch(`/api/dashboard/deployments/${id}/retry`, { method: 'POST' });
            const data = await res.json();
            if (data.error) {
                showBanner('error', data.error);
            } else {
                showBanner('success', 'Retry job successfully dispatched. Checking back in 3s.');
                setTimeout(() => loadData(), 3000);
            }
        } catch (e) {
            showBanner('error', 'Retry dispatcher failed.');
        } finally {
            setActionLoading(null);
        }
    };

    const handleApprovePayment = async (id) => {
        if (!confirm('Verify payment proof and approve this deployment?')) return;
        setActionLoading(id);
        try {
            const res = await fetch(`/api/dashboard/deployments/${id}/approve`, { method: 'POST' });
            const data = await res.json();
            if (data.error) {
                showBanner('error', data.error);
            } else {
                showBanner('success', 'Deployment approved. Client notified.');
                loadData();
            }
        } catch (e) {
            showBanner('error', 'Approval API connection failed.');
        } finally {
            setActionLoading(null);
        }
    };

    const handleExtendSubmit = async () => {
        if (!selectedDeployment) return;
        setLoading(true);
        try {
            const res = await fetch(`/api/dashboard/deployments/${selectedDeployment.id}/extend`, {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ duration: extendDuration }),
            });
            const data = await res.json();
            if (data.error) {
                showBanner('error', data.error);
            } else {
                showBanner('success', `Deployment extended by ${extendDuration.replace('_', ' ')}.`);
                setExtendModalOpen(false);
                loadData();
            }
        } catch (e) {
            showBanner('error', 'Extension API call failed.');
        } finally {
            setLoading(false);
        }
    };

    const getStatusVariant = (status) => {
        switch (status) {
            case 'active': return 'success';
            case 'pending': return 'warning';
            case 'pending_payment': return 'warning';
            case 'expired': return 'neutral';
            case 'failed': return 'danger';
            default: return 'neutral';
        }
    };

    const formatRemainingTime = (expiryString) => {
        if (!expiryString) return '';
        const expiry = new Date(expiryString);
        const diffMs = expiry - new Date();
        const diffDays = Math.ceil(diffMs / (1000 * 60 * 60 * 24));

        if (diffDays < 0) {
            return `Expired ${Math.abs(diffDays)}d ago`;
        }
        if (diffDays === 0) {
            return 'Expires today';
        }
        return `${diffDays}d remaining`;
    };

    if (loading && !stats) {
        return (
            <div className="flex items-center justify-center min-h-[400px]">
                <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-white"></div>
            </div>
        );
    }

    return (
        <div className="space-y-8 font-mono">
            {/* Header */}
            <div className="flex flex-col sm:flex-row sm:items-center justify-between gap-4">
                <div>
                    <h1 className="text-xl font-bold text-white tracking-tight uppercase">Overview</h1>
                    <p className="text-zinc-500 text-xs mt-1">Audit active virtual client instances in real time.</p>
                </div>
                <Button size="sm" variant="secondary" onClick={loadData} loading={loading} className="w-full sm:w-auto">
                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                    </svg>
                    <span>Refresh</span>
                </Button>
            </div>

            {/* Notification Banner */}
            {banner && (
                <Alert 
                    type={banner.type} 
                    message={banner.text} 
                    onClose={() => setBanner(null)} 
                />
            )}

            {/* Stats Cards */}
            <div className="grid grid-cols-1 md:grid-cols-3 lg:grid-cols-5 gap-6">
                <StatCard 
                    title="Active Instances" 
                    value={stats?.total_active ?? 0}
                    icon={
                        <svg className="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M9 12l2 2 4-4m5.618-4.016A11.955 11.955 0 0112 2.944a11.955 11.955 0 01-8.618 3.04A12.02 12.02 0 003 9c0 5.591 3.824 10.29 9 11.622 5.176-1.332 9-6.03 9-11.622 0-1.042-.133-2.052-.382-3.016z" />
                        </svg>
                    }
                    description="Running client instances"
                    badge="Wildcard SSL"
                />
                <StatCard 
                    title="Pending Queue" 
                    value={stats?.total_pending ?? 0}
                    icon={
                        <svg className="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    }
                    description="Awaiting processing"
                    badge="Sync/DB"
                />
                <StatCard 
                    title="Failed Builds" 
                    value={stats?.total_failed ?? 0}
                    icon={
                        <svg className="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z" />
                        </svg>
                    }
                    description="Execution logs error count"
                    badge={stats?.total_failed > 0 ? "Review Required" : "No Errors"}
                />
                <StatCard 
                    title="Total Revenue" 
                    value={stats?.total_revenue ? `IDR ${stats.total_revenue.toLocaleString('id-ID')}` : 'IDR 0'}
                    icon={
                        <svg className="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 6v12m-3-2.818l.879.659c1.171.879 3.07.879 4.242 0 1.172-.879 1.172-2.303 0-3.182C13.536 12.219 12.768 12 12 12c-.725 0-1.45-.22-2.003-.659-1.106-.879-1.106-2.303 0-3.182s2.9-.879 4.006 0l.415.33M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                        </svg>
                    }
                    description="Total sales revenue"
                    badge="IDR Currency"
                />
                <StatCard 
                    title="Registered Blueprints" 
                    value={stats?.total_templates ?? 0}
                    icon={
                        <svg className="w-5 h-5 text-zinc-400" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                        </svg>
                    }
                    description="Active system templates"
                />
            </div>

            {/* Deployments Section */}
            <Card title="Deployments">
                {/* Mobile View: Cards Stack */}
                <div className="md:hidden space-y-4 -mx-2">
                    {deployments.length === 0 ? (
                        <div className="py-8 text-center text-zinc-600 font-mono text-xs">
                            NO DEPLOYMENTS FOUND
                        </div>
                    ) : (
                        deployments.map((dep) => (
                            <div key={dep.id} className="p-4 bg-zinc-950 border border-zinc-900 rounded-lg space-y-3">
                                <div className="flex items-start justify-between">
                                    <div>
                                        <span className="font-sans font-bold text-zinc-200 block text-sm">{dep.client_slug}</span>
                                        {dep.status === 'active' && (
                                            <a 
                                                href={getDeploymentUrl(dep.client_slug)} 
                                                target="_blank" 
                                                rel="noopener noreferrer"
                                                className="text-[10px] text-zinc-400 hover:text-white font-mono flex items-center gap-0.5 mt-0.5 underline decoration-zinc-700 hover:decoration-zinc-400"
                                            >
                                                {dep.client_slug}.mockbuild.shop
                                                <svg className="w-2.5 h-2.5 text-zinc-500 inline" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                                                    <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                </svg>
                                            </a>
                                        )}
                                        <span className="text-[9px] text-zinc-600 font-mono block mt-0.5 tracking-tight">
                                            ID: {dep.lead_reference || 'NONE'}
                                        </span>
                                    </div>
                                    <Badge variant={getStatusVariant(dep.status)}>{dep.status}</Badge>
                                </div>
                                <div className="grid grid-cols-3 gap-2 text-[10px] text-zinc-500 font-mono pt-2 border-t border-zinc-900">
                                    <div>
                                        <span className="text-zinc-600 block uppercase text-[8px]">Type</span>
                                        <span className="text-zinc-300 truncate block w-full">{dep.service_template?.name || dep.service_template_id}</span>
                                    </div>
                                    <div>
                                        <span className="text-zinc-600 block uppercase text-[8px]">Channel</span>
                                        <span className="text-zinc-400 uppercase">{dep.source}</span>
                                    </div>
                                    <div>
                                        <span className="text-zinc-600 block uppercase text-[8px]">Harga</span>
                                        <span className="text-white font-bold">{dep.price ? `IDR ${dep.price.toLocaleString('id-ID')}` : '-'}</span>
                                    </div>
                                    {dep.status === 'active' && (
                                        <div className="col-span-2 mt-1">
                                            <span className="text-zinc-600 block uppercase text-[8px]">Expirations</span>
                                            <span className="text-zinc-300 font-semibold">{new Date(dep.expires_at).toLocaleDateString()} ({formatRemainingTime(dep.expires_at)})</span>
                                        </div>
                                    )}
                                </div>
                                <div className="flex items-center gap-2 pt-2 border-t border-zinc-900 justify-end">
                                    {dep.status === 'active' && (
                                        <>
                                            <Button 
                                                size="sm" 
                                                variant="secondary"
                                                className="flex-1"
                                                onClick={() => {
                                                    setSelectedDeployment(dep);
                                                    setExtendModalOpen(true);
                                                }}
                                            >
                                                Extend
                                            </Button>
                                            <Button 
                                                size="sm" 
                                                variant="danger"
                                                className="flex-1"
                                                loading={actionLoading === dep.id}
                                                onClick={() => handleTeardown(dep.id)}
                                            >
                                                Teardown
                                            </Button>
                                        </>
                                    )}
                                    {dep.status === 'failed' && (
                                        <Button 
                                            size="sm" 
                                            variant="warning"
                                            className="w-full"
                                            loading={actionLoading === dep.id}
                                            onClick={() => handleRetry(dep.id)}
                                        >
                                            Retry
                                        </Button>
                                    )}
                                    {dep.status === 'pending_payment' && (
                                        <Button 
                                            size="sm" 
                                            variant="success"
                                            className="w-full"
                                            loading={actionLoading === dep.id}
                                            onClick={() => handleApprovePayment(dep.id)}
                                        >
                                            Approve Payment
                                        </Button>
                                    )}
                                </div>
                            </div>
                        ))
                    )}
                </div>

                {/* Desktop View: Clean Table */}
                <div className="hidden md:block overflow-x-auto -mx-6">
                    <table className="w-full text-left border-collapse text-xs">
                        <thead>
                            <tr className="border-b border-zinc-800 text-zinc-500 font-semibold uppercase tracking-wider bg-zinc-950/50">
                                <th className="px-6 py-4">Client ID / Slug</th>
                                <th className="px-6 py-4">Service Blueprint</th>
                                <th className="px-6 py-4">Channel</th>
                                <th className="px-6 py-4">Harga</th>
                                <th className="px-6 py-4">Status</th>
                                <th className="px-6 py-4">Expirations</th>
                                <th className="px-6 py-4 text-right">Actions</th>
                            </tr>
                        </thead>
                        <tbody className="divide-y divide-zinc-900">
                            {deployments.length === 0 ? (
                                <tr>
                                    <td colSpan="7" className="px-6 py-12 text-center text-zinc-600 font-mono">
                                        NO DEPLOYMENTS FOUND
                                    </td>
                                </tr>
                            ) : (
                                deployments.map((dep) => (
                                    <tr key={dep.id} className="hover:bg-zinc-950 transition-colors">
                                        <td className="px-6 py-4 font-sans">
                                            <span className="font-bold text-zinc-200 block">{dep.client_slug}</span>
                                            {dep.status === 'active' && (
                                                <a 
                                                    href={getDeploymentUrl(dep.client_slug)} 
                                                    target="_blank" 
                                                    rel="noopener noreferrer"
                                                    className="text-[10px] text-zinc-400 hover:text-white font-mono flex items-center gap-0.5 mt-0.5 underline decoration-zinc-700 hover:decoration-zinc-400"
                                                >
                                                    {dep.client_slug}.mockbuild.shop
                                                    <svg className="w-2.5 h-2.5 text-zinc-500 inline" fill="none" stroke="currentColor" strokeWidth="2.5" viewBox="0 0 24 24">
                                                        <path strokeLinecap="round" strokeLinejoin="round" d="M13.5 6H5.25A2.25 2.25 0 003 8.25v10.5A2.25 2.25 0 005.25 21h10.5A2.25 2.25 0 0018 18.75V10.5m-10.5 6L21 3m0 0h-5.25M21 3v5.25" />
                                                    </svg>
                                                </a>
                                            )}
                                            <span className="text-[10px] text-zinc-600 font-mono block mt-0.5 tracking-tight">
                                                ID: {dep.lead_reference || 'NONE'}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-zinc-300 font-mono">
                                            {dep.service_template?.name || dep.service_template_id}
                                        </td>
                                        <td className="px-6 py-4">
                                            <span className="uppercase text-[10px] text-zinc-400">
                                                {dep.source}
                                            </span>
                                        </td>
                                        <td className="px-6 py-4 text-zinc-300 font-mono font-bold">
                                            {dep.price ? `IDR ${dep.price.toLocaleString('id-ID')}` : '-'}
                                        </td>
                                        <td className="px-6 py-4">
                                            <Badge variant={getStatusVariant(dep.status)}>{dep.status}</Badge>
                                        </td>
                                        <td className="px-6 py-4 text-[11px] text-zinc-400">
                                            {dep.status === 'active' ? (
                                                <span className="flex flex-col gap-0.5 font-mono">
                                                    <strong>{new Date(dep.expires_at).toLocaleDateString()}</strong>
                                                    <span className="text-[10px] text-zinc-650 font-medium">
                                                        ({formatRemainingTime(dep.expires_at)})
                                                    </span>
                                                </span>
                                            ) : '-'}
                                        </td>
                                        <td className="px-6 py-4 text-right">
                                            <div className="flex items-center justify-end gap-2.5">
                                                {dep.status === 'active' && (
                                                    <>
                                                        <Button 
                                                            size="sm" 
                                                            variant="secondary"
                                                            onClick={() => {
                                                                setSelectedDeployment(dep);
                                                                setExtendModalOpen(true);
                                                            }}
                                                        >
                                                            Extend
                                                        </Button>
                                                        <Button 
                                                            size="sm" 
                                                            variant="danger"
                                                            loading={actionLoading === dep.id}
                                                            onClick={() => handleTeardown(dep.id)}
                                                        >
                                                            Teardown
                                                        </Button>
                                                    </>
                                                )}
                                                {dep.status === 'failed' && (
                                                    <Button 
                                                        size="sm" 
                                                        variant="warning"
                                                        loading={actionLoading === dep.id}
                                                        onClick={() => handleRetry(dep.id)}
                                                    >
                                                        Retry
                                                    </Button>
                                                )}
                                                {dep.status === 'pending_payment' && (
                                                    <Button 
                                                        size="sm" 
                                                        variant="success"
                                                        loading={actionLoading === dep.id}
                                                        onClick={() => handleApprovePayment(dep.id)}
                                                    >
                                                        Approve Payment
                                                    </Button>
                                                )}
                                            </div>
                                        </td>
                                    </tr>
                                ))
                            )}
                        </tbody>
                    </table>
                </div>
            </Card>

            {/* Extend Modal */}
            <Modal
                isOpen={extendModalOpen}
                onClose={() => setExtendModalOpen(false)}
                title={`Extend License`}
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setExtendModalOpen(false)}>Cancel</Button>
                        <Button variant="primary" onClick={handleExtendSubmit}>Apply Extension</Button>
                    </>
                }
            >
                <div className="space-y-4 font-mono text-xs">
                    <p className="text-zinc-400">
                        Target Instance: <strong className="text-white">{selectedDeployment?.client_slug}</strong>
                    </p>
                    <div className="space-y-1.5">
                        <label className="block font-semibold text-zinc-400 uppercase">Extend Duration</label>
                        <select
                            value={extendDuration}
                            onChange={(e) => setExtendDuration(e.target.value)}
                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white focus:outline-none focus:border-zinc-500 font-mono"
                        >
                            <option value="1_minggu">+1 Week</option>
                            <option value="1_bulan">+1 Month</option>
                            <option value="3_bulan">+3 Months</option>
                            <option value="6_bulan">+6 Months</option>
                            <option value="1_tahun">+1 Year</option>
                        </select>
                    </div>
                </div>
            </Modal>
        </div>
    );
}
