<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { ChevronDown, FilePlus } from '@lucide/vue';
import { computed, ref } from 'vue';
import { toast } from 'vue-sonner';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuItem,
    DropdownMenuLabel,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { Spinner } from '@/components/ui/spinner';
import { ApiError, apiRequest } from '@/lib/api';
import { formatDateTime } from '@/lib/format';
import { store as storeReport } from '@/routes/api/projects/scans/reports';
import type { RecentScan } from '@/types';

const props = defineProps<{ projectId: number; scans: RecentScan[] }>();

/** Scans with imported results that are not being imported right now. */
const reportable = computed(() =>
    props.scans.filter(
        (scan) =>
            scan.imported_at !== null &&
            !['queued', 'importing'].includes(scan.status),
    ),
);

const generating = ref(false);

async function generate(scan: RecentScan) {
    generating.value = true;

    try {
        await apiRequest(
            storeReport({ project: props.projectId, scan: scan.id }),
        );
        toast.success(`Generating the PDF report for “${scan.name}”…`);
        router.reload({ only: ['dashboard'] });
    } catch (error) {
        toast.error(
            error instanceof ApiError
                ? error.message
                : 'The report could not be started.',
        );
    } finally {
        generating.value = false;
    }
}
</script>

<template>
    <!-- One scan: generate straight away -->
    <Button
        v-if="reportable.length <= 1"
        size="sm"
        :disabled="generating || reportable.length === 0"
        :title="
            reportable.length === 0
                ? 'Import a scan first to generate its report.'
                : `Generate a report for ${reportable[0].name}`
        "
        @click="reportable[0] && generate(reportable[0])"
    >
        <Spinner v-if="generating" />
        <FilePlus v-else />
        Generate report
    </Button>

    <!-- Several scans: pick which one -->
    <DropdownMenu v-else>
        <DropdownMenuTrigger as-child>
            <Button size="sm" :disabled="generating">
                <Spinner v-if="generating" />
                <FilePlus v-else />
                Generate report
                <ChevronDown class="opacity-70" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-72">
            <DropdownMenuLabel class="text-muted-foreground text-xs">
                Report on which scan?
            </DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuItem
                v-for="scan in reportable"
                :key="scan.id"
                class="flex-col items-start gap-0.5"
                @select="generate(scan)"
            >
                <span class="font-medium">{{ scan.name }}</span>
                <span class="text-muted-foreground text-xs">
                    {{ scan.targets }} ·
                    {{ formatDateTime(scan.finished_at ?? scan.imported_at) }}
                </span>
            </DropdownMenuItem>
        </DropdownMenuContent>
    </DropdownMenu>
</template>
