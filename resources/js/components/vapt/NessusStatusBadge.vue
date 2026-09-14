<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { NessusServerStatus } from '@/types';

const props = defineProps<{ status: NessusServerStatus }>();

const styles: Record<NessusServerStatus, { label: string; class: string }> = {
    connected: {
        label: 'Connected',
        class: 'border-emerald-600/20 bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    not_ready: {
        label: 'Not ready',
        class: 'border-amber-600/20 bg-amber-500/10 text-amber-700 dark:text-amber-400',
    },
    unauthorized: {
        label: 'Invalid API keys',
        class: 'border-red-600/20 bg-red-500/10 text-red-700 dark:text-red-400',
    },
    failed: {
        label: 'Unreachable',
        class: 'border-red-600/20 bg-red-500/10 text-red-700 dark:text-red-400',
    },
    unknown: {
        label: 'Not tested',
        class: 'text-muted-foreground',
    },
};

const style = computed(() => styles[props.status] ?? styles.unknown);
</script>

<template>
    <Badge variant="outline" :class="cn('gap-1.5', style.class)">
        <span class="size-1.5 rounded-full bg-current" aria-hidden="true" />
        {{ style.label }}
    </Badge>
</template>
