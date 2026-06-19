import React from 'react';

export const Badge = ({ children, variant = 'neutral', className = '' }) => {
    const baseStyles = 'inline-flex items-center gap-1.5 px-2.5 py-0.5 rounded text-[10px] font-mono font-semibold border uppercase tracking-wider';

    const variants = {
        success: 'text-zinc-200 bg-zinc-900 border-zinc-800',
        danger: 'text-red-400 bg-red-950/20 border-red-900/40',
        warning: 'text-amber-400 bg-amber-950/20 border-amber-900/40',
        info: 'text-zinc-300 bg-zinc-900 border-zinc-800',
        neutral: 'text-zinc-500 bg-zinc-950 border-zinc-900',
    };

    return (
        <span className={`${baseStyles} ${variants[variant]} ${className}`}>
            {children}
        </span>
    );
};
