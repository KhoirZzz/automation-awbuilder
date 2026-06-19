import React from 'react';
import { Button } from './Button';

export const Modal = ({ isOpen, onClose, title, children, footer, className = '' }) => {
    if (!isOpen) return null;

    return (
        <div className="fixed inset-0 z-50 flex items-center justify-center p-4">
            {/* Backdrop */}
            <div className="absolute inset-0 bg-black/85 backdrop-blur-xs transition-opacity duration-300" onClick={onClose} />

            {/* Modal Content */}
            <div className={`relative bg-zinc-950 border border-zinc-800 rounded-lg w-full max-w-lg shadow-2xl p-6 transition-all ${className}`}>
                {/* Header */}
                <div className="flex items-center justify-between pb-4 border-b border-zinc-800">
                    <h3 className="font-bold text-base text-zinc-100 uppercase tracking-wider font-mono">{title}</h3>
                    <button 
                        onClick={onClose} 
                        className="text-zinc-400 hover:text-white transition-colors p-1 rounded hover:bg-zinc-900"
                    >
                        <svg className="w-4 h-4" fill="none" stroke="currentColor" strokeWidth="2" viewBox="0 0 24 24">
                            <path strokeLinecap="round" strokeLinejoin="round" d="M6 18L18 6M6 6l12 12" />
                        </svg>
                    </button>
                </div>

                {/* Body */}
                <div className="py-5 text-sm text-zinc-300">
                    {children}
                </div>

                {/* Footer */}
                <div className="flex items-center justify-end gap-2.5 pt-4 border-t border-zinc-800">
                    {footer ? footer : (
                        <Button variant="secondary" onClick={onClose}>Close</Button>
                    )}
                </div>
            </div>
        </div>
    );
};
