<script setup lang="ts">
import { Badge } from '@/components/ui/badge';
import { cn } from '@/lib/utils';
import type { SeverityKey } from '@/types';
import { SEVERITY_STYLES } from './severity';

defineProps<{ severity: SeverityKey; count?: number }>();
</script>

<template>
    <Badge
        :class="cn('border-transparent', SEVERITY_STYLES[severity].badge)"
        :title="SEVERITY_STYLES[severity].label"
    >
        {{ count ?? SEVERITY_STYLES[severity].label }}
    </Badge>
</template>
