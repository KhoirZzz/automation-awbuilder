import React from 'react';

export const Button = ({ 
    children, 
    onClick, 
    type = 'button', 
    variant = 'primary', 
    size = 'md', 
    disabled = false, 
    loading = false,
    className = '' 
}) => {
    const baseStyles = 'inline-flex items-center justify-center font-medium rounded-lg transition-all duration-150 focus:outline-none focus:ring-1 focus:ring-zinc-400 active:scale-[0.98] disabled:opacity-30 disabled:pointer-events-none disabled:active:scale-100';
    
    const variants = {
        primary: 'bg-white hover:bg-zinc-200 text-black border border-white',
        secondary: 'bg-zinc-900 hover:bg-zinc-800/80 border border-zinc-800 text-zinc-300',
        success: 'bg-zinc-900 hover:bg-zinc-800 border border-zinc-800 text-zinc-100',
        danger: 'bg-black hover:bg-rose-950/20 border border-rose-900/60 hover:border-rose-700 text-rose-400',
        warning: 'bg-black hover:bg-amber-950/20 border border-amber-900/60 hover:border-amber-700 text-amber-400',
    };

    const sizes = {
        sm: 'text-xs px-3 py-1.5 gap-1.5',
        md: 'text-sm px-4.5 py-2 gap-2',
        lg: 'text-base px-5.5 py-2.5 gap-2.5',
    };

    return (
        <button
            type={type}
            onClick={onClick}
            disabled={disabled || loading}
            className={`${baseStyles} ${variants[variant]} ${sizes[size]} ${className}`}
        >
            {loading && (
                <svg className="animate-spin -ml-1 mr-1 h-4 w-4 text-current" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24">
                    <circle className="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" strokeWidth="4"></circle>
                    <path className="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                </svg>
            )}
            {children}
        </button>
    );
};
