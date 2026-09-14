<script setup lang="ts">
import { Head, Link, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { Bug, Crosshair, Pencil, Server, ServerCog } from '@lucide/vue';
import { computed, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import ConfirmDeleteButton from '@/components/vapt/ConfirmDeleteButton.vue';
import EmptyState from '@/components/vapt/EmptyState.vue';
import ImportNessusScanDialog from '@/components/vapt/ImportNessusScanDialog.vue';
import NessusStatusBadge from '@/components/vapt/NessusStatusBadge.vue';
import ScanStatusBadge from '@/components/vapt/ScanStatusBadge.vue';
import SeverityBadge from '@/components/vapt/SeverityBadge.vue';
import SeveritySummary from '@/components/vapt/SeveritySummary.vue';
import StatCard from '@/components/vapt/StatCard.vue';
import { formatDateTime, titleCase } from '@/lib/format';
import { destroy, edit, index, show } from '@/routes/projects';
import { show as showScan } from '@/routes/projects/scans';
import type { Project, ProjectDashboard } from '@/types';

const props = defineProps<{
    project: Project;
    dashboard: ProjectDashboard;
    can: { importScans: boolean };
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Projects', href: index() },
        { title: props.project.name, href: show(props.project.id) },
    ],
});

// Refresh while an import runs on the queue.
const importing = computed(() =>
    props.dashboard.recent_scans.some((scan) =>
        ['queued', 'importing'].includes(scan.status),
    ),
);
const poll = usePoll(3000, { only: ['dashboard'] }, { autoStart: false });
watch(importing, (active) => (active ? poll.start() : poll.stop()), {
    immediate: true,
});
</script>

<template>
    <Head :title="project.name" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="text-muted-foreground font-mono text-xs tracking-wide"
                >
                    {{ project.code }}
                </p>
                <Heading
                    :title="project.name"
                    :description="project.description ?? undefined"
                />
                <div class="-mt-6 flex flex-wrap gap-2">
                    <Badge variant="secondary">
                        {{ titleCase(project.environment) }}
                    </Badge>
                    <Badge variant="outline">
                        {{ titleCase(project.status) }}
                    </Badge>
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <ImportNessusScanDialog
                    v-if="can.importScans"
                    :project-id="project.id"
                />
                <Button v-if="project.can.update" variant="outline" as-child>
                    <Link :href="edit(project.id)"><Pencil /> Edit</Link>
                </Button>
                <ConfirmDeleteButton
                    v-if="project.can.delete"
                    :action="destroy(project.id)"
                    :title="`Delete ${project.name}?`"
                    description="Only projects without scans or reports can be deleted. Archive the project instead to keep its history."
                />
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard label="Assets" :value="dashboard.assets" :icon="Server" />
            <StatCard
                label="Scans"
                :value="dashboard.scans"
                :icon="Crosshair"
            />
            <StatCard
                label="Open vulnerabilities"
                :value="dashboard.open_vulnerabilities"
                :icon="Bug"
            />
        </div>

        <SeveritySummary :counts="dashboard.severity" />

        <div class="grid gap-6 lg:grid-cols-2">
            <section class="space-y-3">
                <h3 class="text-sm font-medium">Recent scans</h3>
                <EmptyState
                    v-if="dashboard.recent_scans.length === 0"
                    :icon="Crosshair"
                    title="No scans yet"
                    description="Run a scan in the Nessus web UI, then import its results into this project."
                >
                    <ImportNessusScanDialog
                        v-if="can.importScans"
                        :project-id="project.id"
                    />
                </EmptyState>
                <ul v-else class="divide-y rounded-xl border">
                    <li v-for="scan in dashboard.recent_scans" :key="scan.id">
                        <Link
                            :href="
                                showScan({ project: project.id, scan: scan.id })
                            "
                            class="hover:bg-muted/50 flex flex-wrap items-center justify-between gap-2 p-3 transition-colors"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ scan.name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ scan.targets }} ·
                                    {{
                                        formatDateTime(
                                            scan.finished_at ?? scan.created_at,
                                        )
                                    }}
                                </p>
                                <p
                                    v-if="scan.status === 'failed'"
                                    class="mt-1 text-xs text-red-700 dark:text-red-400"
                                >
                                    {{ scan.error_message }}
                                </p>
                            </div>
                            <div class="flex items-center gap-1">
                                <SeverityBadge
                                    severity="critical"
                                    :count="scan.critical_count"
                                />
                                <SeverityBadge
                                    severity="high"
                                    :count="scan.high_count"
                                />
                                <SeverityBadge
                                    severity="medium"
                                    :count="scan.medium_count"
                                />
                                <ScanStatusBadge :status="scan.status" />
                            </div>
                        </Link>
                    </li>
                </ul>
            </section>

            <section class="space-y-3">
                <h3 class="text-sm font-medium">Top vulnerable hosts</h3>
                <EmptyState
                    v-if="dashboard.top_hosts.length === 0"
                    :icon="Server"
                    title="No findings yet"
                    description="Hosts appear here once scan results are imported."
                />
                <ul v-else class="divide-y rounded-xl border">
                    <li
                        v-for="host in dashboard.top_hosts"
                        :key="host.asset_id"
                        class="flex items-center justify-between gap-2 p-3"
                    >
                        <div>
                            <p class="font-mono text-sm">
                                {{ host.ip_address }}
                            </p>
                            <p class="text-muted-foreground text-xs">
                                {{ host.hostname ?? '—' }} ·
                                {{ host.total }} open
                            </p>
                        </div>
                        <div class="flex gap-1">
                            <SeverityBadge
                                severity="critical"
                                :count="host.critical"
                            />
                            <SeverityBadge severity="high" :count="host.high" />
                        </div>
                    </li>
                </ul>
            </section>
        </div>

        <section class="space-y-3">
            <h3 class="text-sm font-medium">Nessus servers</h3>
            <EmptyState
                v-if="!project.nessus_servers?.length"
                :icon="ServerCog"
                title="No Nessus server assigned"
                description="An administrator can assign one on the edit page."
            />
            <ul v-else class="flex flex-wrap gap-2">
                <li
                    v-for="server in project.nessus_servers"
                    :key="server.id"
                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                >
                    {{ server.name }}
                    <NessusStatusBadge :status="server.status" />
                </li>
            </ul>
        </section>
    </div>
</template>
