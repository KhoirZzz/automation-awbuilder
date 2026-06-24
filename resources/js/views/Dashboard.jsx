import React, { useState, useEffect } from 'react';
import { StatCard, Card } from '../components/Card';
import { Badge } from '../components/Badge';
import { Button } from '../components/Button';
import { Modal } from '../components/Modal';
import { Alert } from '../components/Alert';

const getDeploymentUrl = (clientSlug) => {
    const hostname = window.location.hostname;
    const baseDomain = hostname.replace(/^(admin|dashboard|www|api)\./i, '');
    return `http://${clientSlug}.${baseDomain}`;
};

export default function Dashboard({ onTriggerAgentEdit }) {
    const [stats, setStats] = useState(null);
    const [deployments, setDeployments] = useState([]);
    const [loading, setLoading] = useState(true);
    const [actionLoading, setActionLoading] = useState(null);
    const [banner, setBanner] = useState(null); // { type: 'success'|'error', text }
    const [optimizing, setOptimizing] = useState(false);
    
    // Modal states
    const [extendModalOpen, setExtendModalOpen] = useState(false);
    const [selectedDeployment, setSelectedDeployment] = useState(null);
    const [extendDuration, setExtendDuration] = useState('1_minggu');

    // Custom Confirm Modal states
    const [confirmOpen, setConfirmOpen] = useState(false);
    const [confirmTitle, setConfirmTitle] = useState('Konfirmasi');
    const [confirmMessage, setConfirmMessage] = useState('');
    const [onConfirm, setOnConfirm] = useState(null);

    // Custom Approve Payment Modal states
    const [approveModalOpen, setApproveModalOpen] = useState(false);
    const [approvePrice, setApprovePrice] = useState('');
    const [approveDeploymentId, setApproveDeploymentId] = useState(null);

    const showConfirm = (title, message, callback) => {
        setConfirmTitle(title);
        setConfirmMessage(message);
        setOnConfirm(() => callback);
        setConfirmOpen(true);
    };

    // File Manager (Manual Edit Deployed Code) states
    const [fmOpen, setFmOpen] = useState(false);
    const [fmDeployment, setFmDeployment] = useState(null);
    const [fmPath, setFmPath] = useState('');
    const [fmFiles, setFmFiles] = useState([]);
    const [fmSelectedFile, setFmSelectedFile] = useState(null);
    const [fmContent, setFmContent] = useState('');
    const [fmLoadingFiles, setFmLoadingFiles] = useState(false);
    const [fmLoadingContent, setFmLoadingContent] = useState(false);
    const [fmSaving, setFmSaving] = useState(false);
    const [fmNewType, setFmNewType] = useState('file'); // 'file' or 'folder'
    const [fmNewName, setFmNewName] = useState('');
    const [fmNewInputOpen, setFmNewInputOpen] = useState(false);
    const [fmRenamingItem, setFmRenamingItem] = useState(null);
    const [fmRenameValue, setFmRenameValue] = useState('');

    const openFileManager = (deployment) => {
        setFmDeployment(deployment);
        setFmPath('');
        setFmFiles([]);
        setFmSelectedFile(null);
        setFmContent('');
        setFmRenamingItem(null);
        setFmRenameValue('');
        setFmOpen(true);
        loadFmFiles(deployment.client_slug, '');
    };

    const loadFmFiles = async (clientSlug, path) => {
        setFmLoadingFiles(true);
        try {
            const res = await fetch(`/api/dashboard/deployments/files?client_slug=${clientSlug}&path=${encodeURIComponent(path)}`);
            const data = await res.json();
            if (res.status === 200) {
                setFmFiles(data);
                setFmPath(path);
            } else {
                showBanner('error', data.error || 'Gagal memuat daftar file.');
            }
        } catch (e) {
            showBanner('error', 'Gagal memuat file instansi.');
        } finally {
            setFmLoadingFiles(false);
        }
    };

    const selectFileForEditing = async (file) => {
        setFmSelectedFile(file);
        setFmLoadingContent(true);
        try {
            const res = await fetch(`/api/dashboard/deployments/file/content?client_slug=${fmDeployment.client_slug}&path=${encodeURIComponent(file.path)}`);
            const data = await res.json();
            if (res.status === 200) {
                setFmContent(data.content);
            } else {
                showBanner('error', data.error || 'Gagal membaca isi file.');
                setFmSelectedFile(null);
            }
        } catch (e) {
            showBanner('error', 'Gagal memuat isi file.');
            setFmSelectedFile(null);
        } finally {
            setFmLoadingContent(false);
        }
    };

    const saveFmFile = async () => {
        if (!fmSelectedFile) return;
        setFmSaving(true);
        try {
            const res = await fetch('/api/dashboard/deployments/file', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    client_slug: fmDeployment.client_slug,
                    path: fmSelectedFile.path,
                    content: fmContent
                })
            });
            const data = await res.json();
            if (res.status === 200 && data.success) {
                showBanner('success', `File "${fmSelectedFile.name}" berhasil disimpan.`);
            } else {
                showBanner('error', data.error || 'Gagal menyimpan file.');
            }
        } catch (e) {
            showBanner('error', 'Gagal menyimpan file. Hubungi administrator.');
        } finally {
            setFmSaving(false);
        }
    };

    const createFmItem = async () => {
        if (!fmNewName.trim()) return;
        const targetPath = fmPath ? `${fmPath}/${fmNewName.trim()}` : fmNewName.trim();
        const isDir = fmNewType === 'folder';

        try {
            const res = await fetch('/api/dashboard/deployments/file', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    client_slug: fmDeployment.client_slug,
                    path: targetPath,
                    is_dir: isDir,
                    content: isDir ? null : ''
                })
            });
            const data = await res.json();
            if (res.status === 200 && data.success) {
                showBanner('success', `${isDir ? 'Folder' : 'File'} berhasil dibuat.`);
                setFmNewInputOpen(false);
                setFmNewName('');
                loadFmFiles(fmDeployment.client_slug, fmPath);
            } else {
                showBanner('error', data.error || 'Gagal membuat file/folder.');
            }
        } catch (e) {
            showBanner('error', 'Gagal menghubungi server.');
        }
    };

    const deleteFmItem = (item, e) => {
        e.stopPropagation();
        showConfirm('Hapus File/Folder', `Apakah Anda yakin ingin menghapus "${item.name}"?`, async () => {
            try {
                const res = await fetch('/api/dashboard/deployments/file', {
                    method: 'DELETE',
                    headers: { 'Content-Type': 'application/json' },
                    body: JSON.stringify({
                        client_slug: fmDeployment.client_slug,
                        path: item.path
                    })
                });
                const data = await res.json();
                if (res.status === 200 && data.success) {
                    showBanner('success', `Berhasil menghapus "${item.name}".`);
                    if (fmSelectedFile && fmSelectedFile.path === item.path) {
                        setFmSelectedFile(null);
                        setFmContent('');
                    }
                    loadFmFiles(fmDeployment.client_slug, fmPath);
                } else {
                    showBanner('error', data.error || 'Gagal menghapus file/folder.');
                }
            } catch (e) {
                showBanner('error', 'Gagal menghubungi server.');
            }
        });
    };

    const startRenameFmItem = (item, e) => {
        e.stopPropagation();
        setFmRenamingItem(item);
        setFmRenameValue(item.name);
    };

    const submitRenameFmItem = async () => {
        if (!fmRenamingItem || !fmRenameValue.trim() || fmRenameValue.trim() === fmRenamingItem.name) {
            setFmRenamingItem(null);
            return;
        }

        try {
            const res = await fetch('/api/dashboard/deployments/file/rename', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    client_slug: fmDeployment.client_slug,
                    path: fmRenamingItem.path,
                    new_name: fmRenameValue.trim()
                })
            });
            const data = await res.json();
            if (res.status === 200 && data.success) {
                showBanner('success', 'Berhasil mengubah nama file/folder.');
                
                // If the selected file was renamed, update its reference
                if (fmSelectedFile && fmSelectedFile.path === fmRenamingItem.path) {
                    const newPath = fmRenamingItem.path.includes('/')
                        ? fmRenamingItem.path.substring(0, fmRenamingItem.path.lastIndexOf('/')) + '/' + fmRenameValue.trim()
                        : fmRenameValue.trim();
                    setFmSelectedFile({
                        ...fmSelectedFile,
                        name: fmRenameValue.trim(),
                        path: newPath
                    });
                }
                
                setFmRenamingItem(null);
                loadFmFiles(fmDeployment.client_slug, fmPath);
            } else {
                showBanner('error', data.error || 'Gagal mengubah nama.');
            }
        } catch (e) {
            showBanner('error', 'Gagal menghubungi server.');
        }
    };

    const navigateUp = () => {
        if (!fmPath) return;
        const parts = fmPath.split('/');
        parts.pop();
        const parentPath = parts.join('/');
        loadFmFiles(fmDeployment.client_slug, parentPath);
    };

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

    const handleOptimize = async () => {
        setOptimizing(true);
        try {
            const res = await fetch('/api/dashboard/optimize', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                }
            });
            const data = await res.json();
            if (data.success) {
                showBanner('success', data.message || 'System optimization completed successfully!');
            } else {
                showBanner('error', data.error || 'Failed to perform optimization.');
            }
        } catch (e) {
            console.error('Error performing optimization', e);
            showBanner('error', 'Network error during optimization.');
        } finally {
            setOptimizing(false);
        }
    };

    useEffect(() => {
        loadData();
    }, []);

    const handleTeardown = (id) => {
        showConfirm('Teardown & Archive', 'Are you sure you want to teardown and archive this deployment?', async () => {
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
        });
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

    const handleDeleteDeployment = (id) => {
        showConfirm('Hapus Riwayat', 'Apakah Anda yakin ingin menghapus riwayat deployment ini?', async () => {
            setActionLoading(id);
            try {
                const res = await fetch(`/api/dashboard/deployments/${id}`, { method: 'DELETE' });
                const data = await res.json();
                if (data.error) {
                    showBanner('error', data.error);
                } else {
                    showBanner('success', 'Riwayat deployment berhasil dihapus.');
                    loadData();
                }
            } catch (e) {
                showBanner('error', 'Gagal menghubungi server.');
            } finally {
                setActionLoading(null);
            }
        });
    };

    const handleApprovePayment = (id) => {
        const deployment = deployments.find(d => d.id === id);
        const currentPrice = deployment ? (deployment.price || '') : '';
        
        setApproveDeploymentId(id);
        setApprovePrice(currentPrice || '150000');
        setApproveModalOpen(true);
    };

    const handleApprovePaymentSubmit = async () => {
        if (!approveDeploymentId) return;
        setApproveModalOpen(false);
        setActionLoading(approveDeploymentId);
        try {
            const res = await fetch(`/api/dashboard/deployments/${approveDeploymentId}/approve`, { 
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ price: approvePrice })
            });
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
            setApproveDeploymentId(null);
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

    const demoDeployments = deployments.filter(dep => dep.client_slug && dep.client_slug.startsWith('demo-'));
    const productionDeployments = deployments.filter(dep => !dep.client_slug || !dep.client_slug.startsWith('demo-'));

    const renderDeploymentsTable = (list, sectionTitle) => {
        return (
            <Card title={sectionTitle}>
                {/* Mobile View: Cards Stack */}
                <div className="md:hidden space-y-4 -mx-2">
                    {list.length === 0 ? (
                        <div className="py-8 text-center text-zinc-650 font-mono text-xs">
                            NO DEPLOYMENTS FOUND
                        </div>
                    ) : (
                        list.map((dep) => (
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
                                <div className="flex flex-wrap gap-2 pt-2 border-t border-zinc-900 justify-end w-full">
                                    {dep.status === 'active' && (
                                        <>
                                            <Button 
                                                size="sm" 
                                                variant="secondary"
                                                className="flex-1 min-w-[120px] font-semibold text-center"
                                                onClick={() => onTriggerAgentEdit && onTriggerAgentEdit(dep.client_slug)}
                                            >
                                                Edit (AI)
                                            </Button>
                                            <Button 
                                                size="sm" 
                                                variant="secondary"
                                                className="flex-1 min-w-[120px] font-semibold text-center"
                                                onClick={() => openFileManager(dep)}
                                            >
                                                Edit (Manual)
                                            </Button>
                                            <Button 
                                                size="sm" 
                                                variant="secondary"
                                                className="flex-1 min-w-[80px] text-center"
                                                onClick={() => {
                                                    setSelectedDeployment(dep);
                                                    setExtendDuration('1_minggu');
                                                    setExtendModalOpen(true);
                                                }}
                                            >
                                                Extend
                                            </Button>
                                            <Button 
                                                size="sm" 
                                                variant="danger"
                                                className="flex-1 min-w-[80px] text-center"
                                                loading={actionLoading === dep.id}
                                                onClick={() => handleTeardown(dep.id)}
                                            >
                                                Teardown
                                            </Button>
                                        </>
                                    )}
                                    {dep.status === 'failed' && (
                                        <>
                                            <Button 
                                                size="sm" 
                                                variant="warning"
                                                className="flex-1 min-w-[80px]"
                                                loading={actionLoading === dep.id}
                                                onClick={() => handleRetry(dep.id)}
                                            >
                                                Retry
                                            </Button>
                                            <Button 
                                                size="sm" 
                                                variant="danger"
                                                className="flex-1 min-w-[80px]"
                                                loading={actionLoading === dep.id}
                                                onClick={() => handleDeleteDeployment(dep.id)}
                                            >
                                                Hapus
                                            </Button>
                                        </>
                                    )}
                                    {dep.status === 'expired' && (
                                        <Button 
                                            size="sm" 
                                            variant="danger"
                                            className="w-full"
                                            loading={actionLoading === dep.id}
                                            onClick={() => handleDeleteDeployment(dep.id)}
                                        >
                                            Hapus
                                        </Button>
                                    )}
                                    {dep.status === 'pending_payment' && (
                                        <>
                                            <Button 
                                                size="sm" 
                                                variant="success"
                                                className="flex-1 min-w-[120px]"
                                                loading={actionLoading === dep.id}
                                                onClick={() => handleApprovePayment(dep.id)}
                                            >
                                                Approve Payment
                                            </Button>
                                            <Button 
                                                size="sm" 
                                                variant="danger"
                                                className="flex-1 min-w-[80px]"
                                                loading={actionLoading === dep.id}
                                                onClick={() => handleDeleteDeployment(dep.id)}
                                            >
                                                Hapus
                                            </Button>
                                        </>
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
                            {list.length === 0 ? (
                                <tr>
                                    <td colSpan="7" className="px-6 py-12 text-center text-zinc-650 font-mono">
                                        NO DEPLOYMENTS FOUND
                                    </td>
                                </tr>
                            ) : (
                                list.map((dep) => (
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
                                                            className="font-semibold"
                                                            onClick={() => onTriggerAgentEdit && onTriggerAgentEdit(dep.client_slug)}
                                                        >
                                                            Edit (AI)
                                                        </Button>
                                                        <Button 
                                                            size="sm" 
                                                            variant="secondary"
                                                            className="font-semibold"
                                                            onClick={() => openFileManager(dep)}
                                                        >
                                                            Edit (Manual)
                                                        </Button>
                                                        <Button 
                                                            size="sm" 
                                                            variant="secondary"
                                                            onClick={() => {
                                                                setSelectedDeployment(dep);
                                                                setExtendDuration('1_minggu');
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
                                                    <>
                                                        <Button 
                                                            size="sm" 
                                                            variant="warning"
                                                            loading={actionLoading === dep.id}
                                                            onClick={() => handleRetry(dep.id)}
                                                        >
                                                            Retry
                                                        </Button>
                                                        <Button 
                                                            size="sm" 
                                                            variant="danger"
                                                            loading={actionLoading === dep.id}
                                                            onClick={() => handleDeleteDeployment(dep.id)}
                                                        >
                                                            Hapus
                                                        </Button>
                                                    </>
                                                )}
                                                {dep.status === 'expired' && (
                                                    <Button 
                                                        size="sm" 
                                                        variant="danger"
                                                        loading={actionLoading === dep.id}
                                                        onClick={() => handleDeleteDeployment(dep.id)}
                                                    >
                                                        Hapus
                                                    </Button>
                                                )}
                                                {dep.status === 'pending_payment' && (
                                                    <>
                                                        <Button 
                                                            size="sm" 
                                                            variant="success"
                                                            loading={actionLoading === dep.id}
                                                            onClick={() => handleApprovePayment(dep.id)}
                                                        >
                                                            Approve Payment
                                                        </Button>
                                                        <Button 
                                                            size="sm" 
                                                            variant="danger"
                                                            loading={actionLoading === dep.id}
                                                            onClick={() => handleDeleteDeployment(dep.id)}
                                                        >
                                                            Hapus
                                                        </Button>
                                                    </>
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
        );
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
                    <p className="text-zinc-550 text-xs mt-1">Audit active virtual client instances in real time.</p>
                </div>
                <div className="flex flex-col sm:flex-row items-center gap-3 w-full sm:w-auto">
                    <Button 
                        size="sm" 
                        variant="secondary" 
                        onClick={handleOptimize} 
                        loading={optimizing} 
                        className="w-full sm:w-auto border-zinc-800 text-zinc-300 hover:text-white"
                    >
                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M3.75 13.5l10.5-11.25L12 10.5h8.25L9.75 21.75 12 13.5H3.75z" />
                        </svg>
                        <span>Optimize</span>
                    </Button>
                    <Button size="sm" variant="secondary" onClick={loadData} loading={loading} className="w-full sm:w-auto">
                        <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M16.023 9.348h4.992v-.001M2.985 19.644v-4.992m0 0h4.992m-4.993 0l3.181 3.183a8.25 8.25 0 0013.803-3.7M4.031 9.865a8.25 8.25 0 0113.803-3.7l3.181 3.182m0-4.991v4.99" />
                        </svg>
                        <span>Refresh</span>
                    </Button>
                </div>
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

            {/* Deployments Sections */}
            {renderDeploymentsTable(productionDeployments, "Production Deployments")}

            {renderDeploymentsTable(demoDeployments, "Demo Deployments")}

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

            {/* Approve Payment Modal */}
            <Modal
                isOpen={approveModalOpen}
                onClose={() => setApproveModalOpen(false)}
                title="Approve Payment & Aktifkan"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setApproveModalOpen(false)}>Batal</Button>
                        <Button variant="success" onClick={handleApprovePaymentSubmit}>Setujui & Aktifkan</Button>
                    </>
                }
            >
                <div className="space-y-4 font-mono text-xs">
                    <p className="text-zinc-400">
                        Konfirmasi nominal pembayaran (Rupiah) untuk mengaktifkan instansi ini secara penuh.
                    </p>
                    <div className="space-y-1.5">
                        <label className="block font-semibold text-zinc-400 uppercase">Nominal Pembayaran (IDR)</label>
                        <input
                            type="number"
                            value={approvePrice}
                            onChange={(e) => setApprovePrice(e.target.value)}
                            placeholder="e.g. 150000"
                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white focus:outline-none focus:border-zinc-500 font-mono"
                        />
                    </div>
                </div>
            </Modal>

            {/* Deployed Instance File Manager Modal */}
            <Modal
                isOpen={fmOpen}
                onClose={() => setFmOpen(false)}
                title={fmDeployment ? `File Manager: ${fmDeployment.client_slug}` : 'File Manager'}
                className="w-[98vw] max-w-[98vw] md:max-w-7xl h-[95vh] md:h-[90vh] flex flex-col p-4 md:p-6"
                bodyClassName="flex-1 min-h-0 py-4 flex flex-col"
                footer={
                    <Button variant="secondary" onClick={() => setFmOpen(false)}>Close</Button>
                }
            >
                <div className="grid grid-cols-1 md:grid-cols-12 gap-4 md:gap-6 font-mono text-xs flex-1 min-h-0 overflow-y-auto md:overflow-hidden">
                    {/* Left Pane: Files & Directories */}
                    <div className="md:col-span-4 border border-zinc-800 rounded bg-zinc-950 p-3 md:p-4 space-y-4 flex flex-col h-[350px] md:h-full min-h-[300px]">
                        <div className="flex items-center justify-between border-b border-zinc-900 pb-2">
                            <span className="font-bold text-zinc-400 uppercase tracking-wider text-[10px]">Files & Folders</span>
                            {fmNewInputOpen ? (
                                <button 
                                    onClick={() => setFmNewInputOpen(false)}
                                    className="text-red-500 hover:text-red-400 font-bold"
                                >
                                    Cancel
                                </button>
                            ) : (
                                <div className="flex gap-2">
                                    <button 
                                        onClick={() => { setFmNewType('file'); setFmNewInputOpen(true); }}
                                        className="text-zinc-400 hover:text-white font-bold"
                                        title="Create File"
                                    >
                                        +File
                                    </button>
                                    <button 
                                        onClick={() => { setFmNewType('folder'); setFmNewInputOpen(true); }}
                                        className="text-zinc-400 hover:text-white font-bold"
                                        title="Create Folder"
                                    >
                                        +Folder
                                    </button>
                                </div>
                            )}
                        </div>

                        {/* Create new item form */}
                        {fmNewInputOpen && (
                            <div className="p-2 border border-zinc-800 bg-zinc-900/50 rounded space-y-2">
                                <span className="text-zinc-500 text-[9px] uppercase font-semibold">New {fmNewType}</span>
                                <div className="flex gap-2">
                                    <input 
                                        type="text"
                                        value={fmNewName}
                                        onChange={(e) => setFmNewName(e.target.value)}
                                        placeholder={`Name e.g. ${fmNewType === 'file' ? 'test.html' : 'images'}`}
                                        className="flex-1 bg-zinc-950 border border-zinc-800 rounded px-2 py-1 text-white focus:outline-none focus:border-zinc-500 font-mono text-[10px]"
                                    />
                                    <button 
                                        onClick={createFmItem}
                                        className="bg-white text-black px-2 py-1 rounded font-bold hover:bg-zinc-200"
                                    >
                                        Create
                                    </button>
                                </div>
                            </div>
                        )}

                        {/* Navigation / Breadcrumb */}
                        <div className="flex items-center gap-1.5 flex-wrap bg-zinc-900/40 p-2 border border-zinc-900 rounded font-semibold text-[10px] text-zinc-400">
                            <span 
                                onClick={() => loadFmFiles(fmDeployment.client_slug, '')}
                                className="hover:text-white cursor-pointer"
                            >
                                ROOT
                            </span>
                            {fmPath && fmPath.split('/').map((part, index, arr) => {
                                const currentClickPath = arr.slice(0, index + 1).join('/');
                                return (
                                    <React.Fragment key={index}>
                                        <span>/</span>
                                        <span 
                                            onClick={() => loadFmFiles(fmDeployment.client_slug, currentClickPath)}
                                            className="hover:text-white cursor-pointer truncate max-w-[60px]"
                                            title={part}
                                        >
                                            {part}
                                        </span>
                                    </React.Fragment>
                                );
                            })}
                        </div>

                        {/* Directory List Container */}
                        <div className="flex-1 overflow-y-auto space-y-1.5 scrollbar-none pr-1">
                            {fmLoadingFiles ? (
                                <div className="text-center text-zinc-650 py-8 uppercase tracking-widest text-[9px] animate-pulse">
                                    Loading files...
                                </div>
                            ) : (
                                <>
                                    {fmPath && (
                                        <div 
                                            onClick={navigateUp}
                                            className="flex items-center gap-2 p-2 rounded border border-transparent hover:bg-zinc-900/60 cursor-pointer font-bold text-zinc-550 select-none text-[11px]"
                                        >
                                            📁 ..
                                        </div>
                                    )}
                                    
                                    {fmFiles.length === 0 ? (
                                        <div className="text-center text-zinc-700 py-8 uppercase text-[9px]">
                                            Empty folder.
                                        </div>
                                    ) : (
                                        fmFiles.map((file) => {
                                            const isSelected = fmSelectedFile && fmSelectedFile.path === file.path;
                                            const isRenaming = fmRenamingItem && fmRenamingItem.path === file.path;
                                            
                                            if (isRenaming) {
                                                return (
                                                    <div 
                                                        key={file.path}
                                                        className="flex items-center gap-1.5 p-1 rounded border border-zinc-700 bg-zinc-900"
                                                        onClick={(e) => e.stopPropagation()}
                                                    >
                                                        <input 
                                                            type="text"
                                                            value={fmRenameValue}
                                                            onChange={(e) => setFmRenameValue(e.target.value)}
                                                            className="flex-1 bg-zinc-950 border border-zinc-800 rounded px-1.5 py-0.5 text-white font-mono text-[10px] focus:outline-none focus:border-zinc-500"
                                                            autoFocus
                                                            onKeyDown={(e) => {
                                                                if (e.key === 'Enter') submitRenameFmItem();
                                                                if (e.key === 'Escape') setFmRenamingItem(null);
                                                            }}
                                                        />
                                                        <button 
                                                            onClick={submitRenameFmItem}
                                                            className="text-green-500 hover:text-green-400 font-bold text-[10px] px-1"
                                                            title="Save Rename"
                                                        >
                                                            ✓
                                                        </button>
                                                        <button 
                                                            onClick={() => setFmRenamingItem(null)}
                                                            className="text-red-500 hover:text-red-400 font-bold text-[10px] px-1"
                                                            title="Cancel"
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                );
                                            }

                                            return (
                                                <div 
                                                    key={file.path}
                                                    onClick={() => file.is_dir ? loadFmFiles(fmDeployment.client_slug, file.path) : selectFileForEditing(file)}
                                                    className={`group flex items-center justify-between p-2 rounded border transition-colors cursor-pointer select-none text-[11px] ${
                                                        isSelected 
                                                            ? 'bg-zinc-900 border-zinc-700 text-white font-semibold' 
                                                            : 'bg-zinc-950/20 border-transparent hover:bg-zinc-900/40 text-zinc-450 hover:text-zinc-200'
                                                    }`}
                                                >
                                                    <span className="truncate flex items-center gap-2">
                                                        <span>{file.is_dir ? '📁' : '📄'}</span>
                                                        <span className="truncate">{file.name}</span>
                                                    </span>
                                                    
                                                    <div className="flex items-center gap-2">
                                                        {!file.is_dir && (
                                                            <span className="text-[9px] text-zinc-600 font-mono hidden md:inline">
                                                                {(file.size / 1024).toFixed(1)} KB
                                                            </span>
                                                        )}
                                                        <button 
                                                            onClick={(e) => startRenameFmItem(file, e)}
                                                            className="text-zinc-650 hover:text-white font-semibold text-[10px] md:opacity-0 group-hover:opacity-100 transition-opacity p-0.5"
                                                            title={`Rename ${file.name}`}
                                                        >
                                                            ✏️
                                                        </button>
                                                        <button 
                                                            onClick={(e) => deleteFmItem(file, e)}
                                                            className="text-zinc-650 hover:text-red-500 font-semibold text-[10px] md:opacity-0 group-hover:opacity-100 transition-opacity p-0.5"
                                                            title={`Delete ${file.name}`}
                                                        >
                                                            ✕
                                                        </button>
                                                    </div>
                                                </div>
                                            );
                                        })
                                    )}
                                </>
                            )}
                        </div>
                    </div>

                    {/* Right Pane: Code Editor */}
                    <div className="md:col-span-8 border border-zinc-800 rounded bg-zinc-950 p-3 md:p-4 flex flex-col h-[500px] md:h-full min-h-[400px] space-y-3">
                        {fmSelectedFile ? (
                            <>
                                <div className="flex items-center justify-between border-b border-zinc-900 pb-2.5">
                                    <div className="flex flex-col">
                                        <span className="font-bold text-zinc-200 text-[11px] truncate max-w-[280px]">
                                            {fmSelectedFile.name}
                                        </span>
                                        <span className="text-[9px] text-zinc-650 font-mono">
                                            {fmSelectedFile.path}
                                        </span>
                                    </div>
                                    <Button 
                                        size="sm" 
                                        variant="primary" 
                                        loading={fmSaving}
                                        onClick={saveFmFile}
                                        className="uppercase font-semibold tracking-wider font-mono text-[9px]"
                                    >
                                        Save Changes
                                    </Button>
                                </div>

                                <div className="flex-1 relative border border-zinc-900 bg-zinc-950 rounded overflow-hidden">
                                    {fmLoadingContent ? (
                                        <div className="absolute inset-0 bg-black/60 flex items-center justify-center text-zinc-550 uppercase tracking-widest text-[9px] font-bold font-mono animate-pulse">
                                            Reading file stream...
                                        </div>
                                    ) : (
                                        <textarea
                                            value={fmContent}
                                            onChange={(e) => setFmContent(e.target.value)}
                                            className="w-full h-full bg-zinc-950 text-zinc-300 p-4 font-mono text-xs leading-relaxed focus:outline-none focus:ring-0 resize-none border-0 select-text"
                                            placeholder="Write content here..."
                                            spellCheck="false"
                                        />
                                    )}
                                </div>
                            </>
                        ) : (
                            <div className="flex-1 flex flex-col items-center justify-center text-center p-8 text-zinc-700 border border-dashed border-zinc-900 rounded select-none">
                                <span className="text-[20px] mb-2">📄</span>
                                <span className="text-[9px] uppercase tracking-wider font-bold">No file selected</span>
                                <p className="text-[10px] text-zinc-650 mt-1 max-w-xs leading-normal">
                                    Select a text file from the sidebar list to modify its contents in real-time.
                                </p>
                            </div>
                        )}
                    </div>
                </div>
            </Modal>
        </div>
    );
}
