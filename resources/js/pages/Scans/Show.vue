<script setup lang="ts">
import { Head, router, setLayoutProps, usePoll } from '@inertiajs/vue3';
import { Bug, Clock, RefreshCw, Search, Server } from '@lucide/vue';
import { computed, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import EmptyState from '@/components/vapt/EmptyState.vue';
import PluginDetailSheet from '@/components/vapt/PluginDetailSheet.vue';
import ScanStatusBadge from '@/components/vapt/ScanStatusBadge.vue';
import SeverityBadge from '@/components/vapt/SeverityBadge.vue';
import SeveritySummary from '@/components/vapt/SeveritySummary.vue';
import StatCard from '@/components/vapt/StatCard.vue';
import { SEVERITIES, SEVERITY_STYLES } from '@/components/vapt/severity';
import { ApiError, apiRequest } from '@/lib/api';
import { formatDateTime, formatDuration } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index as projectsIndex, show as showProject } from '@/routes/projects';
import { sync } from '@/routes/api/projects/scans';
import { show } from '@/routes/projects/scans';
import type { Scan, ScanFinding, ScanHost, SeverityKey } from '@/types';

const props = defineProps<{
    project: { id: number; code: string; name: string };
    scan: Scan;
    hosts: ScanHost[];
    findings: ScanFinding[];
    can: { sync: boolean };
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Projects', href: projectsIndex() },
        { title: props.project.name, href: showProject(props.project.id) },
        {
            title: props.scan.name,
            href: show({ project: props.project.id, scan: props.scan.id }),
        },
    ],
});

const importing = computed(() =>
    ['queued', 'importing'].includes(props.scan.status),
);
const poll = usePoll(
    3000,
    { only: ['scan', 'hosts', 'findings'] },
    { autoStart: false },
);
watch(importing, (active) => (active ? poll.start() : poll.stop()), {
    immediate: true,
});

const syncing = ref(false);

async function resync() {
    syncing.value = true;

    try {
        await apiRequest(
            sync({ project: props.project.id, scan: props.scan.id }),
        );
        toast.success('Re-sync started. The page updates when it finishes.');
        router.reload({ only: ['scan'] });
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'The re-sync failed.',
        );
    } finally {
        syncing.value = false;
    }
}

const severityFilter = ref<SeverityKey | null>(null);
const search = ref('');

const visibleFindings = computed(() => {
    const term = search.value.trim().toLowerCase();

    return props.findings.filter(
        (finding) =>
            (severityFilter.value === null ||
                finding.severity === severityFilter.value) &&
            (term === '' ||
                finding.name.toLowerCase().includes(term) ||
                String(finding.plugin_id).includes(term) ||
                (finding.family ?? '').toLowerCase().includes(term)),
    );
});

const findingCounts = computed(() => {
    const counts = Object.fromEntries(
        SEVERITIES.map((key) => [key, 0]),
    ) as Record<SeverityKey, number>;

    for (const finding of props.findings) {
        counts[finding.severity]++;
    }

    return counts;
});

function toggleSeverity(key: SeverityKey) {
    severityFilter.value = severityFilter.value === key ? null : key;
}

const sheetOpen = ref(false);
const selectedPlugin = ref<number | null>(null);

function openFinding(finding: ScanFinding) {
    selectedPlugin.value = finding.plugin_id;
    sheetOpen.value = true;
}

const notice = computed(() => {
    if (!props.scan.error_message || importing.value) {
        return null;
    }

    return {
        message: props.scan.error_message,
        failed: props.scan.status === 'failed',
    };
});
</script>

<template>
    <Head :title="scan.name" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <div>
                <p
                    class="text-muted-foreground font-mono text-xs tracking-wide"
                >
                    {{ project.code }} · Nessus scan
                    {{ scan.nessus_scan_id ?? '—' }}
                </p>
                <Heading :title="scan.name" :description="scan.targets" />
                <div class="-mt-6 flex flex-wrap items-center gap-2">
                    <ScanStatusBadge :status="scan.status" />
                    <span
                        v-if="scan.nessus_server"
                        class="text-muted-foreground text-xs"
                    >
                        from {{ scan.nessus_server.name }}
                    </span>
                </div>
            </div>

            <Button
                v-if="can.sync && scan.nessus_scan_id"
                variant="outline"
                :disabled="syncing || importing"
                @click="resync"
            >
                <Spinner v-if="syncing || importing" />
                <RefreshCw v-else />
                {{ importing ? 'Importing…' : 'Re-sync from Nessus' }}
            </Button>
        </div>

        <div
            v-if="importing"
            role="status"
            class="flex items-center gap-2 rounded-lg border border-violet-600/30 bg-violet-500/5 p-3 text-sm text-violet-800 dark:text-violet-300"
        >
            <Spinner />
            Importing results from Nessus. This page refreshes automatically.
        </div>

        <p
            v-if="notice"
            role="status"
            :class="
                cn(
                    'rounded-lg border p-3 text-sm',
                    notice.failed
                        ? 'border-red-600/30 bg-red-500/5 text-red-800 dark:text-red-300'
                        : 'border-amber-600/30 bg-amber-500/5 text-amber-800 dark:text-amber-300',
                )
            "
        >
            {{ notice.message }}
        </p>

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard
                label="Hosts"
                :value="scan.scanned_hosts"
                :icon="Server"
            />
            <StatCard
                label="Findings"
                :value="scan.total_findings"
                :icon="Bug"
                :hint="`${findings.length} distinct plugins`"
            />
            <StatCard
                label="Duration"
                :value="formatDuration(scan.duration_seconds)"
                :icon="Clock"
                :hint="`Finished ${formatDateTime(scan.finished_at)}`"
            />
        </div>

        <SeveritySummary :counts="scan.severity" title="Findings by severity" />

        <section class="space-y-3">
            <h3 class="text-sm font-medium">Hosts</h3>
            <EmptyState
                v-if="hosts.length === 0"
                :icon="Server"
                title="No hosts"
                :description="
                    importing
                        ? 'Hosts appear once the import finishes.'
                        : 'Nessus reported no hosts for this scan.'
                "
            />
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-left">
                        <tr>
                            <th class="px-3 py-2 font-medium">Host</th>
                            <th class="px-3 py-2 font-medium">
                                Operating system
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Findings
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr v-for="host in hosts" :key="host.id">
                            <td class="px-3 py-2">
                                <p class="font-mono">{{ host.ip_address }}</p>
                                <p
                                    v-if="host.hostname || host.fqdn"
                                    class="text-muted-foreground text-xs"
                                >
                                    {{ host.fqdn ?? host.hostname }}
                                </p>
                            </td>
                            <td class="px-3 py-2">
                                {{ host.operating_system ?? '—' }}
                            </td>
                            <td class="px-3 py-2">
                                <div class="flex justify-end gap-1">
                                    <SeverityBadge
                                        v-for="key in SEVERITIES"
                                        :key="key"
                                        :severity="key"
                                        :count="host.severity[key]"
                                    />
                                </div>
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="space-y-3">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <h3 class="text-sm font-medium">
                    Vulnerabilities
                    <span class="text-muted-foreground font-normal">
                        ({{ visibleFindings.length }} of {{ findings.length }})
                    </span>
                </h3>
                <div class="relative w-full sm:w-64">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                    />
                    <Input
                        v-model="search"
                        type="search"
                        placeholder="Search name, plugin ID or family"
                        class="pl-8"
                    />
                </div>
            </div>

            <div class="flex flex-wrap gap-2">
                <button
                    v-for="key in SEVERITIES"
                    :key="key"
                    type="button"
                    :aria-pressed="severityFilter === key"
                    :class="
                        cn(
                            'rounded-full border px-3 py-1 text-xs transition-colors',
                            severityFilter === key
                                ? SEVERITY_STYLES[key].badge +
                                      ' border-transparent'
                                : 'hover:bg-muted',
                        )
                    "
                    @click="toggleSeverity(key)"
                >
                    {{ SEVERITY_STYLES[key].label }}
                    <span class="tabular-nums opacity-80">
                        {{ findingCounts[key] }}
                    </span>
                </button>
            </div>

            <EmptyState
                v-if="visibleFindings.length === 0"
                :icon="Bug"
                :title="
                    findings.length === 0 ? 'No findings' : 'Nothing matches'
                "
                :description="
                    findings.length === 0
                        ? 'Nessus reported no vulnerabilities for this scan.'
                        : 'Clear the search or severity filter.'
                "
            />
            <div v-else class="overflow-x-auto rounded-xl border">
                <table class="w-full text-sm">
                    <thead class="bg-muted/50 text-muted-foreground text-left">
                        <tr>
                            <th class="w-24 px-3 py-2 font-medium">Severity</th>
                            <th class="px-3 py-2 font-medium">Name</th>
                            <th class="px-3 py-2 font-medium">Family</th>
                            <th class="px-3 py-2 text-right font-medium">
                                CVSS
                            </th>
                            <th class="px-3 py-2 text-right font-medium">
                                Count
                            </th>
                        </tr>
                    </thead>
                    <tbody class="divide-y">
                        <tr
                            v-for="finding in visibleFindings"
                            :key="finding.plugin_id"
                            tabindex="0"
                            class="hover:bg-muted/50 focus-visible:bg-muted/50 cursor-pointer outline-none"
                            @click="openFinding(finding)"
                            @keydown.enter="openFinding(finding)"
                        >
                            <td class="px-3 py-2">
                                <SeverityBadge :severity="finding.severity" />
                            </td>
                            <td class="px-3 py-2">
                                <p class="font-medium">{{ finding.name }}</p>
                                <p
                                    class="text-muted-foreground font-mono text-xs"
                                >
                                    {{ finding.plugin_id }}
                                </p>
                            </td>
                            <td class="text-muted-foreground px-3 py-2">
                                {{ finding.family ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ finding.cvss_score ?? '—' }}
                            </td>
                            <td class="px-3 py-2 text-right tabular-nums">
                                {{ finding.instances }}
                            </td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </section>

        <PluginDetailSheet
            v-model:open="sheetOpen"
            :project-id="project.id"
            :scan-id="scan.id"
            :plugin-id="selectedPlugin"
        />
    </div>
</template>
