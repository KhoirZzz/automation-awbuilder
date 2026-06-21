import React, { useState, useEffect, useRef } from 'react';
import { Card } from '../components/Card';
import { Button } from '../components/Button';
import { Badge } from '../components/Badge';
import { Modal } from '../components/Modal';
import { Alert } from '../components/Alert';

export default function Templates() {
    const [templates, setTemplates] = useState([]);
    const [zips, setZips] = useState([]);
    const [loading, setLoading] = useState(true);
    const [modalOpen, setModalOpen] = useState(false);
    const [banner, setBanner] = useState(null); // { type: 'success'|'error'|'warning', text }

    // File Manager / Editor States
    const [fmOpen, setFmOpen] = useState(false);
    const [fmTemplate, setFmTemplate] = useState(null);
    const [fmPath, setFmPath] = useState('');
    const [fmFiles, setFmFiles] = useState([]);
    const [fmSelectedFile, setFmSelectedFile] = useState(null); // { name, path }
    const [fmContent, setFmContent] = useState('');
    const [fmLoadingFiles, setFmLoadingFiles] = useState(false);
    const [fmLoadingContent, setFmLoadingContent] = useState(false);
    const [fmSaving, setFmSaving] = useState(false);
    const [fmNewInputOpen, setFmNewInputOpen] = useState(false);
    const [fmNewName, setFmNewName] = useState('');
    const [fmNewType, setFmNewType] = useState('file'); // 'file' | 'folder'

    const showBanner = (type, text) => {
        setBanner({ type, text });
    };
    
    // Manual template form states
    const [newKey, setNewKey] = useState('');
    const [newName, setNewName] = useState('');
    const [newCategory, setNewCategory] = useState('');
    const [newPath, setNewPath] = useState('');
    const [newIsActive, setNewIsActive] = useState(true);

    // ZIP upload state
    const [selectedFile, setSelectedFile] = useState(null);
    const [uploading, setUploading] = useState(false);

    // Extraction form states (keyed by zip filename)
    const [extractionKeys, setExtractionKeys] = useState({});
    const [extractionNames, setExtractionNames] = useState({});
    const [extractionCategories, setExtractionCategories] = useState({});

    // Hold-to-extract state
    const [holdingZip, setHoldingZip] = useState(null);
    const [holdProgress, setHoldProgress] = useState(0);
    const holdIntervalRef = useRef(null);

    const fetchTemplates = async () => {
        try {
            const res = await fetch('/api/dashboard/templates');
            const data = await res.json();
            setTemplates(data);
        } catch (e) {
            console.error('Error fetching templates', e);
        }
    };

    const fetchZips = async () => {
        try {
            const res = await fetch('/api/dashboard/templates/zips');
            const data = await res.json();
            setZips(data);
        } catch (e) {
            console.error('Error fetching zips', e);
        }
    };

    const loadAllData = async () => {
        setLoading(true);
        await Promise.all([fetchTemplates(), fetchZips()]);
        setLoading(false);
    };

    useEffect(() => {
        loadAllData();
    }, []);

    // File Manager / Editor Actions
    const openFileManager = (template) => {
        setFmTemplate(template);
        setFmPath('');
        setFmFiles([]);
        setFmSelectedFile(null);
        setFmContent('');
        setFmOpen(true);
        loadFmFiles(template.key, '');
    };

    const loadFmFiles = async (templateKey, path) => {
        setFmLoadingFiles(true);
        try {
            const res = await fetch(`/api/dashboard/templates/files?template_key=${templateKey}&path=${encodeURIComponent(path)}`);
            const data = await res.json();
            if (res.status === 200) {
                setFmFiles(data);
                setFmPath(path);
            } else {
                showBanner('error', data.error || 'Gagal memuat daftar file.');
            }
        } catch (e) {
            showBanner('error', 'Gagal memuat file template.');
        } finally {
            setFmLoadingFiles(false);
        }
    };

    const selectFileForEditing = async (file) => {
        setFmSelectedFile(file);
        setFmLoadingContent(true);
        try {
            const res = await fetch(`/api/dashboard/templates/file/content?template_key=${fmTemplate.key}&path=${encodeURIComponent(file.path)}`);
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
            const res = await fetch('/api/dashboard/templates/file', {
                method: 'PUT',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    template_key: fmTemplate.key,
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
            const res = await fetch('/api/dashboard/templates/file', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    template_key: fmTemplate.key,
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
                loadFmFiles(fmTemplate.key, fmPath);
            } else {
                showBanner('error', data.error || 'Gagal membuat file/folder.');
            }
        } catch (e) {
            showBanner('error', 'Gagal menghubungi server.');
        }
    };

    const deleteFmItem = async (item, e) => {
        e.stopPropagation();
        if (!confirm(`Apakah Anda yakin ingin menghapus "${item.name}"?`)) {
            return;
        }

        try {
            const res = await fetch('/api/dashboard/templates/file', {
                method: 'DELETE',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    template_key: fmTemplate.key,
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
                loadFmFiles(fmTemplate.key, fmPath);
            } else {
                showBanner('error', data.error || 'Gagal menghapus file/folder.');
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
        loadFmFiles(fmTemplate.key, parentPath);
    };

    const handleToggle = async (id) => {
        try {
            const res = await fetch(`/api/dashboard/templates/${id}/toggle`, { method: 'POST' });
            const data = await res.json();
            if (data.success) {
                fetchTemplates();
                showBanner('success', 'Status blueprint berhasil diubah.');
            }
        } catch (e) {
            showBanner('error', 'Gagal mengubah status blueprint.');
        }
    };

    const handleDelete = async (id, name) => {
        if (!confirm(`Apakah Anda yakin ingin menghapus blueprint "${name}"? Tindakan ini juga akan menghapus folder template dari penyimpanan.`)) {
            return;
        }

        try {
            let res = await fetch(`/api/dashboard/templates/${id}`, {
                method: 'DELETE'
            });
            let data = await res.json();
            
            if (res.status === 200 && data.success) {
                fetchTemplates();
                showBanner('success', `Blueprint "${name}" berhasil dihapus.`);
            } else if (res.status === 400 && data.error && data.error.includes('riwayat/aktif')) {
                if (confirm(`${data.error}\n\nApakah Anda yakin ingin memaksa (force) menghapus blueprint ini? Tindakan ini akan menghentikan (teardown) semua deployment aktif yang terikat dan menghapus seluruh data riwayat deployment terkait.`)) {
                    res = await fetch(`/api/dashboard/templates/${id}?force=true`, {
                        method: 'DELETE'
                    });
                    data = await res.json();
                    if (res.status === 200 && data.success) {
                        fetchTemplates();
                        showBanner('success', `Blueprint "${name}" dan seluruh riwayat terkait berhasil dihapus.`);
                    } else {
                        showBanner('error', data.error || 'Gagal memaksa menghapus template.');
                    }
                }
            } else {
                showBanner('error', data.error || 'Gagal menghapus template.');
            }
        } catch (e) {
            showBanner('error', 'Gagal menghapus template. Hubungi administrator.');
        }
    };

    const handleSubmit = async (e) => {
        e.preventDefault();
        try {
            const res = await fetch('/api/dashboard/templates', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({
                    key: newKey,
                    name: newName,
                    category: newCategory,
                    template_path: newPath,
                    is_active: newIsActive
                })
            });
            const data = await res.json();
            if (data.error) {
                showBanner('error', data.error);
            } else if (res.status === 422) {
                showBanner('error', JSON.stringify(data.errors));
            } else {
                setModalOpen(false);
                setNewKey('');
                setNewName('');
                setNewCategory('');
                setNewPath('');
                fetchTemplates();
                showBanner('success', `Blueprint "${newName}" berhasil diregistrasi secara manual.`);
            }
        } catch (err) {
            showBanner('error', 'Gagal menyimpan blueprint baru.');
        }
    };

    const handleFileChange = (e) => {
        if (e.target.files && e.target.files[0]) {
            setSelectedFile(e.target.files[0]);
        }
    };

    const handleUploadZip = async (e) => {
        e.preventDefault();
        if (!selectedFile) return;

        setUploading(true);
        const formData = new FormData();
        formData.append('zip_file', selectedFile);

        try {
            const res = await fetch('/api/dashboard/templates/upload-zip', {
                method: 'POST',
                body: formData
            });
            const data = await res.json();
            if (data.success) {
                setSelectedFile(null);
                // Clear input
                document.getElementById('zipFileInput').value = '';
                fetchZips();
                showBanner('success', 'Arsip ZIP template berhasil diunggah.');
            } else {
                showBanner('error', data.error || 'Failed to upload ZIP.');
            }
        } catch (err) {
            showBanner('error', 'Gagal mengunggah file.');
        } finally {
            setUploading(false);
        }
    };

    // Hold-to-extract triggers
    const startHold = (zipFilename) => {
        const key = (extractionKeys[zipFilename] || '').trim();
        const name = (extractionNames[zipFilename] || '').trim();
        const category = (extractionCategories[zipFilename] || '').trim();

        if (!key || !name) {
            showBanner('warning', 'Silakan isi Template Key dan Display Name terlebih dahulu.');
            return;
        }

        if (!/^[a-z0-9-]+$/.test(key)) {
            showBanner('warning', 'Template Key harus berupa huruf kecil, angka, atau tanda hubung saja (a-z0-9-).');
            return;
        }

        setHoldingZip(zipFilename);
        setHoldProgress(0);

        holdIntervalRef.current = setInterval(() => {
            setHoldProgress((prev) => {
                if (prev >= 100) {
                    clearInterval(holdIntervalRef.current);
                    triggerExtraction(zipFilename, key, name, category);
                    return 100;
                }
                return prev + 5; // Takes 1.0s to hit 100% (20 ticks of 50ms)
            });
        }, 50);
    };

    const stopHold = () => {
        if (holdIntervalRef.current) {
            clearInterval(holdIntervalRef.current);
        }
        setHoldingZip(null);
        setHoldProgress(0);
    };

    const triggerExtraction = async (filename, key, name, category) => {
        stopHold();
        setLoading(true);
        try {
            const res = await fetch('/api/dashboard/templates/extract-zip', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json' },
                body: JSON.stringify({ filename, key, name, category })
            });
            const data = await res.json();

            if (data.success) {
                // Clear inputs
                setExtractionKeys(prev => {
                    const next = { ...prev };
                    delete next[filename];
                    return next;
                });
                setExtractionNames(prev => {
                    const next = { ...prev };
                    delete next[filename];
                    return next;
                });
                setExtractionCategories(prev => {
                    const next = { ...prev };
                    delete next[filename];
                    return next;
                });
                await loadAllData();
                showBanner('success', `ZIP "${filename}" berhasil diekstrak dan didaftarkan sebagai blueprint "${name}".`);
            } else {
                showBanner('error', data.error || 'Ekstraksi gagal.');
                setLoading(false);
            }
        } catch (err) {
            showBanner('error', 'Koneksi API gagal.');
            setLoading(false);
        }
    };

    const handleDeleteZip = async (filename) => {
        if (!confirm(`Apakah Anda yakin ingin menghapus file ZIP "${filename}"?`)) {
            return;
        }

        setLoading(true);
        try {
            const res = await fetch(`/api/dashboard/templates/zips/${filename}`, {
                method: 'DELETE'
            });
            const data = await res.json();
            if (data.success) {
                await fetchZips();
                showBanner('success', `File ZIP "${filename}" berhasil dihapus.`);
            } else {
                showBanner('error', data.error || 'Gagal menghapus file ZIP.');
            }
        } catch (err) {
            showBanner('error', 'Koneksi API gagal.');
        } finally {
            setLoading(false);
        }
    };

    const formatBytes = (bytes) => {
        if (bytes === 0) return '0 B';
        const k = 1024;
        const sizes = ['B', 'KB', 'MB', 'GB'];
        const i = Math.floor(Math.log(bytes) / Math.log(k));
        return parseFloat((bytes / Math.pow(k, i)).toFixed(2)) + ' ' + sizes[i];
    };

    if (loading && templates.length === 0) {
        return (
            <div className="flex items-center justify-center min-h-[400px]">
                <div className="animate-spin rounded-full h-8 w-8 border-t-2 border-b-2 border-white"></div>
            </div>
        );
    }

    // Group templates by category
    const groupedTemplates = templates.reduce((groups, tpl) => {
        const cat = (tpl.category || 'UNCATEGORIZED').toUpperCase().trim();
        if (!groups[cat]) {
            groups[cat] = [];
        }
        groups[cat].push(tpl);
        return groups;
    }, {});

    return (
        <div className="space-y-10 font-mono text-xs">
            {/* Header section */}
            <div className="flex items-center justify-between">
                <div>
                    <h1 className="text-xl font-bold text-white tracking-tight uppercase">Service Blueprints</h1>
                    <p className="text-zinc-500 text-xs mt-1">Available service configurations injected to LLM context.</p>
                </div>
                <Button onClick={() => setModalOpen(true)} variant="secondary" size="sm">
                    + Register Folder Manually
                </Button>
            </div>

            {banner && (
                <Alert 
                    type={banner.type} 
                    message={banner.text} 
                    onClose={() => setBanner(null)} 
                />
            )}

            {/* Template list Grouped by Categories */}
            <div className="space-y-10">
                {Object.keys(groupedTemplates).map((catName) => (
                    <div key={catName} className="space-y-4">
                        <div className="flex items-center gap-3">
                            <span className="h-px bg-zinc-800 flex-1"></span>
                            <h2 className="text-[10px] font-bold text-zinc-400 tracking-widest uppercase font-mono px-3.5 py-1 bg-zinc-950 border border-zinc-900 rounded">
                                {catName}
                            </h2>
                            <span className="h-px bg-zinc-800 flex-1"></span>
                        </div>
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            {groupedTemplates[catName].map((tpl) => (
                                <Card 
                                    key={tpl.id} 
                                    title={tpl.name}
                                    action={
                                        <Badge variant={tpl.is_active ? 'success' : 'neutral'}>
                                            {tpl.is_active ? 'Active' : 'Inactive'}
                                        </Badge>
                                    }
                                >
                                    <div className="space-y-4">
                                        <div>
                                            <span className="text-zinc-500 text-[10px] font-semibold uppercase tracking-wider block">Service Slug Key</span>
                                            <span className="text-zinc-300 font-mono text-xs font-bold">{tpl.key}</span>
                                        </div>
                                        <div>
                                            <span className="text-zinc-500 text-[10px] font-semibold uppercase tracking-wider block">Template Folder Name</span>
                                            <span className="text-zinc-300 font-mono text-xs">{tpl.template_path}</span>
                                        </div>
                                         <div className="pt-4 border-t border-zinc-900 flex flex-col gap-3">
                                             <div className="flex items-center justify-between">
                                                 <span className="text-zinc-500 text-xs">LLM Active?</span>
                                                 <Button 
                                                     size="sm" 
                                                     variant={tpl.is_active ? 'secondary' : 'success'}
                                                     onClick={() => handleToggle(tpl.id)}
                                                 >
                                                     {tpl.is_active ? 'Deactivate' : 'Activate'}
                                                 </Button>
                                             </div>
                                             <div className="flex items-center justify-between gap-2.5">
                                                 <Button 
                                                     size="sm" 
                                                     variant="secondary"
                                                     className="hover:bg-zinc-850 hover:text-white border-zinc-850 text-zinc-300 font-semibold font-mono flex-1 text-center"
                                                     onClick={() => openFileManager(tpl)}
                                                 >
                                                     Edit Files
                                                 </Button>
                                                 <Button 
                                                     size="sm" 
                                                     variant="secondary"
                                                     className="hover:bg-zinc-800 hover:text-white border-zinc-800 text-zinc-450 font-semibold font-mono"
                                                     onClick={() => handleDelete(tpl.id, tpl.name)}
                                                 >
                                                     Delete
                                                 </Button>
                                             </div>
                                         </div>
                                    </div>
                                </Card>
                            ))}
                        </div>
                    </div>
                ))}
            </div>

            {/* ZIP File Management Section */}
            <div className="border-t border-zinc-900 pt-10 space-y-8">
                <div>
                    <h2 className="text-lg font-bold text-white uppercase tracking-tight">Template File Manager</h2>
                    <p className="text-zinc-500 text-xs mt-1">Upload new website zip files and extract them directly on the server to register new blueprints.</p>
                </div>

                <div className="grid grid-cols-1 lg:grid-cols-12 gap-8">
                    {/* ZIP File Uploader Panel */}
                    <div className="lg:col-span-4">
                        <Card title="Upload ZIP Archive">
                            <form onSubmit={handleUploadZip} className="space-y-5">
                                <div className="space-y-2">
                                    <label className="block font-semibold text-zinc-400 uppercase">Select ZIP File</label>
                                    <div className="border border-dashed border-zinc-800 bg-zinc-950 p-6 rounded-lg text-center cursor-pointer hover:border-zinc-650 transition-colors relative">
                                        <input
                                            type="file"
                                            id="zipFileInput"
                                            accept=".zip"
                                            onChange={handleFileChange}
                                            className="absolute inset-0 w-full h-full opacity-0 cursor-pointer"
                                        />
                                        <svg className="w-8 h-8 text-zinc-600 mx-auto mb-2" fill="none" stroke="currentColor" strokeWidth="1.5" viewBox="0 0 24 24">
                                            <path strokeLinecap="round" strokeLinejoin="round" d="M12 16.5V9.75m0 0l3 3m-3-3l-3 3M6.75 19.5h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                        </svg>
                                        <span className="text-[10px] text-zinc-400 block font-bold uppercase tracking-wider">
                                            {selectedFile ? selectedFile.name : 'Choose ZIP Archive'}
                                        </span>
                                        {selectedFile && (
                                            <span className="text-[9px] text-zinc-600 block mt-1">
                                                {formatBytes(selectedFile.size)}
                                            </span>
                                        )}
                                    </div>
                                </div>
                                <Button 
                                    type="submit" 
                                    variant="primary" 
                                    loading={uploading} 
                                    disabled={!selectedFile || uploading}
                                    className="w-full uppercase font-semibold text-xs tracking-wider"
                                >
                                    Upload File
                                </Button>
                            </form>
                        </Card>
                    </div>

                    {/* Extraction / Manager Panel */}
                    <div className="lg:col-span-8">
                        <Card title="ZIP Archives Pending Extraction">
                            {zips.length === 0 ? (
                                <div className="p-12 border border-dashed border-zinc-900 text-center text-zinc-600 text-xs uppercase">
                                    No uploaded ZIP files found. Upload one to begin.
                                </div>
                            ) : (
                                <div className="space-y-6">
                                    {zips.map((zip) => (
                                        <div 
                                            key={zip.filename} 
                                            className="p-4 bg-zinc-950 border border-zinc-800 rounded-lg flex flex-col md:flex-row md:items-center justify-between gap-4"
                                        >
                                            {/* Left side: file metadata */}
                                            <div className="space-y-1 select-none">
                                                <span className="font-bold text-white block text-sm tracking-tight truncate max-w-xs">{zip.filename}</span>
                                                <div className="flex items-center gap-3 text-[10px] text-zinc-550">
                                                    <span>Size: {formatBytes(zip.size)}</span>
                                                    <span>•</span>
                                                    <span>Uploaded: {new Date(zip.uploaded_at).toLocaleString()}</span>
                                                </div>
                                            </div>

                                            {/* Right side: extraction configurations */}
                                            <div className="flex flex-col sm:flex-row items-stretch sm:items-center gap-2.5">
                                                <input
                                                    type="text"
                                                    required
                                                    placeholder="Template Slug (key)"
                                                    value={extractionKeys[zip.filename] || ''}
                                                    onChange={(e) => {
                                                        const val = e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, '');
                                                        setExtractionKeys(prev => ({ ...prev, [zip.filename]: val }));
                                                    }}
                                                    className="bg-zinc-900 border border-zinc-800 rounded px-2.5 py-2 text-white placeholder-zinc-700 focus:outline-none w-full sm:w-32"
                                                />
                                                <input
                                                    type="text"
                                                    required
                                                    placeholder="Display Name"
                                                    value={extractionNames[zip.filename] || ''}
                                                    onChange={(e) => {
                                                        const val = e.target.value;
                                                        setExtractionNames(prev => ({ ...prev, [zip.filename]: val }));
                                                    }}
                                                    className="bg-zinc-900 border border-zinc-800 rounded px-2.5 py-2 text-white placeholder-zinc-700 focus:outline-none w-full sm:w-36"
                                                />
                                                <input
                                                    type="text"
                                                    placeholder="Category"
                                                    value={extractionCategories[zip.filename] || ''}
                                                    onChange={(e) => {
                                                        const val = e.target.value.toUpperCase();
                                                        setExtractionCategories(prev => ({ ...prev, [zip.filename]: val }));
                                                    }}
                                                    className="bg-zinc-900 border border-zinc-800 rounded px-2.5 py-2 text-white placeholder-zinc-700 focus:outline-none w-full sm:w-28"
                                                />

                                                {/* Hold-to-Extract Button */}
                                                <button
                                                    onMouseDown={() => startHold(zip.filename)}
                                                    onMouseUp={stopHold}
                                                    onMouseLeave={stopHold}
                                                    onTouchStart={() => startHold(zip.filename)}
                                                    onTouchEnd={stopHold}
                                                    className="relative select-none overflow-hidden inline-flex items-center justify-center font-bold rounded-lg border border-white text-xs bg-white text-black active:scale-[0.98] transition-transform duration-100 h-9 w-full sm:w-32"
                                                >
                                                    {/* Progress bar background fill */}
                                                    {holdingZip === zip.filename && (
                                                        <span 
                                                            style={{ width: `${holdProgress}%` }}
                                                            className="absolute left-0 top-0 bottom-0 bg-zinc-800/25 transition-all duration-75"
                                                        />
                                                    )}
                                                    <span className="relative z-10">
                                                        {holdingZip === zip.filename ? `Holding (${holdProgress}%)` : 'Hold to Extract'}
                                                    </span>
                                                </button>

                                                {/* Delete Button */}
                                                <button
                                                    type="button"
                                                    onClick={() => handleDeleteZip(zip.filename)}
                                                    className="inline-flex items-center justify-center font-semibold rounded-lg border border-zinc-800 text-zinc-400 hover:border-red-900 hover:text-red-500 hover:bg-red-950/10 active:scale-[0.98] transition-all text-xs h-9 px-3 w-full sm:w-auto"
                                                    title="Hapus berkas ZIP"
                                                >
                                                    Delete
                                                </button>
                                            </div>
                                        </div>
                                    ))}
                                </div>
                            )}
                        </Card>
                    </div>
                </div>
            </div>

            {/* Create Template Modal */}
            <Modal
                isOpen={modalOpen}
                onClose={() => setModalOpen(false)}
                title="New Template Blueprint"
                footer={
                    <>
                        <Button variant="secondary" onClick={() => setModalOpen(false)}>Cancel</Button>
                        <Button variant="primary" onClick={handleSubmit}>Create</Button>
                    </>
                }
            >
                <form onSubmit={handleSubmit} className="space-y-4 text-left font-mono text-xs">
                    <div className="space-y-1.5">
                        <label className="block font-semibold text-zinc-400 uppercase">Service Key Slug</label>
                        <input
                            type="text"
                            required
                            placeholder="e.g. shopee-bot"
                            value={newKey}
                            onChange={(e) => setNewKey(e.target.value.toLowerCase().replace(/[^a-z0-9-]/g, ''))}
                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-650 focus:outline-none focus:border-zinc-500"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <label className="block font-semibold text-zinc-400 uppercase">Service Display Name</label>
                        <input
                            type="text"
                            required
                            placeholder="e.g. Shopee Auto Bot"
                            value={newName}
                            onChange={(e) => setNewName(e.target.value)}
                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-650 focus:outline-none focus:border-zinc-500"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <label className="block font-semibold text-zinc-400 uppercase">Category Group</label>
                        <input
                            type="text"
                            placeholder="e.g. SHOPEE, GOJEK, GRAB, AGODA"
                            value={newCategory}
                            onChange={(e) => setNewCategory(e.target.value.toUpperCase())}
                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-650 focus:outline-none focus:border-zinc-500"
                        />
                    </div>
                    <div className="space-y-1.5">
                        <label className="block font-semibold text-zinc-400 uppercase">Relative Folder Path</label>
                        <input
                            type="text"
                            required
                            placeholder="e.g. gojek (path under template base dir)"
                            value={newPath}
                            onChange={(e) => setNewPath(e.target.value)}
                            className="w-full bg-zinc-900 border border-zinc-800 rounded px-3 py-2 text-white placeholder-zinc-650 focus:outline-none focus:border-zinc-500"
                        />
                    </div>
                    <div className="flex items-center gap-2.5 pt-2">
                        <input
                            type="checkbox"
                            id="isActiveCheckbox"
                            checked={newIsActive}
                            onChange={(e) => setNewIsActive(e.target.checked)}
                            className="w-3.5 h-3.5 bg-zinc-900 border-zinc-800 text-white rounded focus:ring-zinc-550 focus:ring-offset-0 cursor-pointer"
                        />
                        <label htmlFor="isActiveCheckbox" className="font-semibold text-zinc-450 uppercase cursor-pointer select-none">
                            Activate on creation
                        </label>
                    </div>
                </form>
            </Modal>

            {/* File Manager / Editor Modal */}
            <Modal
                isOpen={fmOpen}
                onClose={() => setFmOpen(false)}
                title={fmTemplate ? `File Manager: ${fmTemplate.name}` : 'File Manager'}
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
                                onClick={() => loadFmFiles(fmTemplate.key, '')}
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
                                            onClick={() => loadFmFiles(fmTemplate.key, currentClickPath)}
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
                                    Loading blueprint files...
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
                                            return (
                                                <div 
                                                    key={file.path}
                                                    onClick={() => file.is_dir ? loadFmFiles(fmTemplate.key, file.path) : selectFileForEditing(file)}
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
                                        <span className="text-[9px] text-zinc-600 font-mono">
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
