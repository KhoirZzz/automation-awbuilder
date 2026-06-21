import React, { useState } from 'react';
import { Button } from '../components/Button';

export default function Login() {
    const [passkey, setPasskey] = useState('');
    const [error, setError] = useState('');
    const [loading, setLoading] = useState(false);

    const handleSubmit = async (e) => {
        e.preventDefault();
        if (passkey.length < 6) {
            setError('Passkey harus terdiri dari 6 digit.');
            return;
        }

        setLoading(true);
        setError('');

        // Temporarily store in localStorage so the fetch interceptor can include it
        localStorage.setItem('admin_passkey', passkey);

        try {
            // Hit a light protected API endpoint to verify the passkey
            const res = await fetch('/api/dashboard/stats');
            
            if (res.status === 200) {
                // Correct passkey! Trigger login event
                window.dispatchEvent(new Event('admin-logged-in'));
            } else {
                localStorage.removeItem('admin_passkey');
                setError('Passkey salah atau tidak diizinkan.');
            }
        } catch (err) {
            localStorage.removeItem('admin_passkey');
            setError('Gagal menghubungkan ke server API.');
        } finally {
            setLoading(false);
        }
    };

    return (
        <div className="min-h-screen bg-black flex flex-col items-center justify-center font-mono px-4 text-xs">
            <div className="w-full max-w-sm border border-zinc-900 bg-zinc-950/70 p-8 rounded-lg space-y-6">
                
                {/* Header */}
                <div className="text-center space-y-2">
                    <img 
                        src="/logo/awbuilder.png" 
                        alt="AWBuilder" 
                        className="h-10 w-10 object-contain rounded mx-auto"
                    />
                    <h2 className="text-zinc-100 font-bold uppercase tracking-widest text-xs mt-3">AWBuilder Control</h2>
                    <p className="text-zinc-550 text-[10px]">Autodeployment System Admin Gateway</p>
                </div>

                {/* Form */}
                <form onSubmit={handleSubmit} className="space-y-4">
                    <div className="space-y-2">
                        <label className="block text-zinc-450 uppercase font-semibold text-[10px] tracking-wider text-center">
                            Masukkan 6-Digit Passkey
                        </label>
                        <input
                            type="password"
                            maxLength="6"
                            placeholder="------"
                            value={passkey}
                            onChange={(e) => {
                                const val = e.target.value.replace(/\D/g, ''); // only digits
                                setPasskey(val);
                                setError('');
                            }}
                            disabled={loading}
                            className="w-full bg-zinc-900 border border-zinc-850 hover:border-zinc-800 focus:border-zinc-600 rounded px-3 py-3.5 text-white text-center font-bold tracking-[1.5em] text-lg placeholder-zinc-700 focus:outline-none disabled:opacity-50"
                        />
                    </div>

                    {error && (
                        <div className="p-2.5 bg-black border border-red-950 rounded text-red-500 text-center text-[10px] leading-relaxed uppercase font-semibold">
                            {error}
                        </div>
                    )}

                    <Button
                        type="submit"
                        variant="primary"
                        loading={loading}
                        disabled={loading || passkey.length < 6}
                        className="w-full uppercase font-bold py-3 tracking-wider text-xs bg-white text-black hover:bg-zinc-200 transition-colors border border-white"
                    >
                        Authenticate Gateway
                    </Button>
                </form>

                {/* Footer */}
                <div className="text-center pt-2 border-t border-zinc-900">
                    <span className="text-zinc-600 text-[9px] uppercase tracking-wider">
                        SECURE HTTPS GATEWAY PROXIED VIA CLOUDFLARE
                    </span>
                </div>

            </div>
        </div>
    );
}
