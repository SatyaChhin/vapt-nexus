import type { SeverityKey } from '@/types';

export const SEVERITIES: SeverityKey[] = [
    'critical',
    'high',
    'medium',
    'low',
    'info',
];

/** Colours follow the conventional Nessus risk palette. */
export const SEVERITY_STYLES: Record<
    SeverityKey,
    { label: string; badge: string; bar: string }
> = {
    critical: {
        label: 'Critical',
        badge: 'bg-red-800 text-white',
        bar: 'bg-red-800',
    },
    high: { label: 'High', badge: 'bg-red-500 text-white', bar: 'bg-red-500' },
    medium: {
        label: 'Medium',
        badge: 'bg-orange-500 text-white',
        bar: 'bg-orange-500',
    },
    low: {
        label: 'Low',
        badge: 'bg-yellow-400 text-yellow-950',
        bar: 'bg-yellow-400',
    },
    info: { label: 'Info', badge: 'bg-sky-500 text-white', bar: 'bg-sky-500' },
};
