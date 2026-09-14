<script setup lang="ts">
import { computed } from 'vue';
import { Badge } from '@/components/ui/badge';
import { titleCase } from '@/lib/format';
import { cn } from '@/lib/utils';
import type { ScanStatus } from '@/types';

const props = defineProps<{ status: ScanStatus }>();

const classes: Partial<Record<ScanStatus, string>> = {
    queued: 'bg-sky-500/10 text-sky-700 dark:text-sky-400',
    running: 'bg-blue-500/10 text-blue-700 dark:text-blue-400',
    completed: 'bg-indigo-500/10 text-indigo-700 dark:text-indigo-400',
    importing: 'bg-violet-500/10 text-violet-700 dark:text-violet-400',
    imported: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    failed: 'bg-red-500/10 text-red-700 dark:text-red-400',
};

const klass = computed(() => classes[props.status] ?? 'text-muted-foreground');
</script>

<template>
    <Badge variant="outline" :class="cn('border-transparent', klass)">
        {{ titleCase(status) }}
    </Badge>
</template>
