<script setup lang="ts">
import { FileText, Trash2 } from '@lucide/vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { formatDateTime } from '@/lib/format';
import { cn } from '@/lib/utils';
import { destroy, download } from '@/routes/projects/reports';
import type { Report, ReportStatus } from '@/types';
import ConfirmDeleteButton from './ConfirmDeleteButton.vue';
import EmptyState from './EmptyState.vue';

defineProps<{
    projectId: number;
    reports: Report[];
    /** Show which scan each report belongs to (project page). */
    showScan?: boolean;
    canDelete?: boolean;
    emptyDescription?: string;
}>();

const statusStyles: Record<ReportStatus, { label: string; class: string }> = {
    pending: { label: 'Queued', class: 'text-muted-foreground' },
    generating: {
        label: 'Generating',
        class: 'bg-violet-500/10 text-violet-700 dark:text-violet-400',
    },
    completed: {
        label: 'Ready',
        class: 'bg-emerald-500/10 text-emerald-700 dark:text-emerald-400',
    },
    failed: {
        label: 'Failed',
        class: 'bg-red-500/10 text-red-700 dark:text-red-400',
    },
};
</script>

<template>
    <EmptyState
        v-if="reports.length === 0"
        :icon="FileText"
        title="No reports yet"
        :description="
            emptyDescription ??
            'A PDF report is generated automatically when a scan finishes.'
        "
    />
    <ul v-else class="divide-y rounded-xl border">
        <li
            v-for="report in reports"
            :key="report.id"
            class="flex flex-wrap items-center justify-between gap-3 p-3"
        >
            <div class="flex min-w-0 items-start gap-3">
                <FileText
                    class="text-muted-foreground mt-0.5 size-4 shrink-0"
                />
                <div class="min-w-0">
                    <p class="font-mono text-sm font-medium">
                        {{ report.report_number }}
                    </p>
                    <p class="text-muted-foreground text-xs">
                        <template v-if="showScan && report.scan_name">
                            {{ report.scan_name }} ·
                        </template>
                        {{
                            formatDateTime(
                                report.generated_at ?? report.created_at,
                            )
                        }}
                        · {{ report.created_by ?? 'Automatic' }}
                    </p>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Badge
                    variant="outline"
                    :class="
                        cn(
                            'border-transparent',
                            statusStyles[report.status].class,
                        )
                    "
                >
                    <Spinner
                        v-if="['pending', 'generating'].includes(report.status)"
                        class="size-3"
                    />
                    {{ statusStyles[report.status].label }}
                </Badge>
                <Button
                    v-if="report.status === 'completed'"
                    variant="outline"
                    size="sm"
                    as-child
                >
                    <a
                        :href="
                            download({ project: projectId, report: report.id })
                                .url
                        "
                        target="_blank"
                        rel="noopener"
                    >
                        <FileText /> Open PDF
                    </a>
                </Button>
                <ConfirmDeleteButton
                    v-if="canDelete"
                    :action="destroy({ project: projectId, report: report.id })"
                    :title="`Delete ${report.report_number}?`"
                    description="The PDF is removed permanently. The report number stays reserved and is not given to another report."
                >
                    <Button
                        variant="ghost"
                        size="icon"
                        class="text-destructive size-8"
                        :aria-label="`Delete ${report.report_number}`"
                        :title="`Delete ${report.report_number}`"
                    >
                        <Trash2 />
                    </Button>
                </ConfirmDeleteButton>
            </div>
        </li>
    </ul>
</template>
