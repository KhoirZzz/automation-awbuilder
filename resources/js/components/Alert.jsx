import React, { useEffect } from 'react';

export const Alert = ({ type = 'success', message, onClose, autoClose = 5000 }) => {
    useEffect(() => {
        if (autoClose && onClose && message) {
            const timer = setTimeout(() => {
                onClose();
            }, autoClose);
            return () => clearTimeout(timer);
        }
    }, [autoClose, onClose, message]);

    if (!message) return null;

    const baseStyles = 'p-3.5 border rounded-lg text-xs font-mono flex items-start justify-between gap-3 shadow-md relative transition-all duration-300';

    const variants = {
        success: 'bg-zinc-950 border-zinc-850 text-zinc-100',
        error: 'bg-red-950/20 border-red-900/40 text-red-400',
        warning: 'bg-amber-950/20 border-amber-900/40 text-amber-400',
        info: 'bg-zinc-950 border-zinc-850 text-zinc-300',
    };

    const titles = {
        success: 'SUCCESS',
        error: 'ERROR',
        warning: 'WARNING',
        info: 'INFO',
    };

    return (
        <div className={`${baseStyles} ${variants[type]}`}>
            <div className="flex-1">
                <span className="font-bold uppercase block text-[10px] mb-1 tracking-widest">
                    {titles[type] || 'NOTIFICATION'}
                </span>
                <span className="leading-relaxed">{message}</span>
            </div>
            {onClose && (
                <button 
                    onClick={onClose}
                    className="text-zinc-500 hover:text-zinc-200 transition-colors focus:outline-none p-0.5 cursor-pointer"
                >
                    <svg className="w-3.5 h-3.5" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                        <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                    </svg>
                </button>
            )}
        </div>
    );
};
