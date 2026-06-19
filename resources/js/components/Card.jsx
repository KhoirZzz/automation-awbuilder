import React from 'react';

export const Card = ({ children, className = '', title, action }) => {
    return (
        <div className={`bg-zinc-950 border border-zinc-800 rounded-lg shadow-sm p-6 ${className}`}>
            {(title || action) && (
                <div className="flex items-center justify-between mb-5 border-b border-zinc-800 pb-4">
                    {title && <h3 className="font-semibold text-base text-zinc-100 tracking-tight">{title}</h3>}
                    {action && <div>{action}</div>}
                </div>
            )}
            {children}
        </div>
    );
};

export const StatCard = ({ title, value, icon, description, badge }) => {
    return (
        <Card className="relative overflow-hidden">
            <div className="flex items-start justify-between">
                <div className="space-y-1">
                    <span className="text-zinc-500 text-xs font-semibold tracking-wider uppercase block">{title}</span>
                    <span className="text-3xl font-bold text-white tracking-tight block">{value}</span>
                </div>
                <div className="text-zinc-400">
                    {icon}
                </div>
            </div>
            
            {(description || badge) && (
                <div className="mt-4 pt-4 border-t border-zinc-900 flex items-center justify-between">
                    {description && <span className="text-zinc-400 text-xs font-medium">{description}</span>}
                    {badge && (
                        <span className="text-[10px] px-2 py-0.5 rounded border border-zinc-800 bg-zinc-900 text-zinc-300 font-mono">
                            {badge}
                        </span>
                    )}
                </div>
            )}
        </Card>
    );
};
