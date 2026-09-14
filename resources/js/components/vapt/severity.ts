import type { SeverityKey } from '@/types';

export const SEVERITIES: SeverityKey[] = [
    'critical',
    'high',
    'medium',
    'low',
    'info',
];

type SeverityStyle = {
    label: string;
    /** Solid badge. */
    badge: string;
    /** Solid fill for bars and dots. */
    bar: string;
    /** Text colour readable on the page background. */
    text: string;
    /** Tinted tile background and border. */
    soft: string;
};

/** Colours follow the conventional Nessus risk palette. */
export const SEVERITY_STYLES: Record<SeverityKey, SeverityStyle> = {
    critical: {
        label: 'Critical',
        badge: 'bg-red-800 text-white',
        bar: 'bg-red-800 dark:bg-red-700',
        text: 'text-red-800 dark:text-red-400',
        soft: 'border-red-800/20 bg-red-800/5 dark:border-red-500/25 dark:bg-red-500/10',
    },
    high: {
        label: 'High',
        badge: 'bg-red-500 text-white',
        bar: 'bg-red-500',
        text: 'text-red-600 dark:text-red-400',
        soft: 'border-red-500/20 bg-red-500/5 dark:border-red-500/25 dark:bg-red-500/10',
    },
    medium: {
        label: 'Medium',
        badge: 'bg-orange-500 text-white',
        bar: 'bg-orange-500',
        text: 'text-orange-600 dark:text-orange-400',
        soft: 'border-orange-500/25 bg-orange-500/5 dark:border-orange-500/25 dark:bg-orange-500/10',
    },
    low: {
        label: 'Low',
        badge: 'bg-yellow-400 text-yellow-950',
        bar: 'bg-yellow-400',
        text: 'text-yellow-700 dark:text-yellow-400',
        soft: 'border-yellow-500/30 bg-yellow-400/10 dark:border-yellow-400/25 dark:bg-yellow-400/10',
    },
    info: {
        label: 'Info',
        badge: 'bg-sky-500 text-white',
        bar: 'bg-sky-500',
        text: 'text-sky-600 dark:text-sky-400',
        soft: 'border-sky-500/20 bg-sky-500/5 dark:border-sky-500/25 dark:bg-sky-500/10',
    },
};
