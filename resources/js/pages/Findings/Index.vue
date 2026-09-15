<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Bug, ChevronLeft, ChevronRight, Search, Server } from '@lucide/vue';
import { useDebounceFn } from '@vueuse/core';
import { computed, reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import EmptyState from '@/components/vapt/EmptyState.vue';
import NativeSelect from '@/components/vapt/NativeSelect.vue';
import PluginDetailSheet from '@/components/vapt/PluginDetailSheet.vue';
import { SEVERITIES, SEVERITY_STYLES } from '@/components/vapt/severity';
import SeverityBadge from '@/components/vapt/SeverityBadge.vue';
import { formatDateTime, formatRelative, titleCase } from '@/lib/format';
import { cn } from '@/lib/utils';
import { index } from '@/routes/findings';
import { show as showProject } from '@/routes/projects';
import type {
    FindingFilters,
    FindingRow,
    Paginated,
    SeverityCounts,
    SeverityKey,
    VulnerabilityState,
} from '@/types';

const props = defineProps<{
    findings: Paginated<FindingRow>;
    counts: SeverityCounts;
    filters: FindingFilters;
    projects: { id: number; code: string; name: string }[];
    states: VulnerabilityState[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Findings', href: index() }],
    },
});

const DEFAULT_SEVERITIES: SeverityKey[] = ['critical', 'high', 'medium', 'low'];

const form = reactive({
    q: props.filters.q,
    host: props.filters.host,
    project: props.filters.project === null ? '' : String(props.filters.project),
    state: props.filters.state,
    severity: [...props.filters.severity],
});
const loading = ref(false);

const projectOptions = computed(() => [
    { value: '', label: 'All projects' },
    ...props.projects.map((p) => ({
        value: String(p.id),
        label: `${p.name} (${p.code})`,
    })),
]);

const stateOptions = computed(() => [
    { value: 'all', label: 'Any state' },
    ...props.states.map((s) => ({ value: s, label: titleCase(s) })),
]);

const isDefaultSeverity = computed(
    () =>
        form.severity.length === DEFAULT_SEVERITIES.length &&
        DEFAULT_SEVERITIES.every((s) => form.severity.includes(s)),
);

const hasFilters = computed(
    () =>
        form.q !== '' ||
        form.host !== '' ||
        form.project !== '' ||
        form.state !== 'open' ||
        !isDefaultSeverity.value,
);

/** Defaults are left out, so a plain /findings URL is the default view. */
function query(): Record<string, string> {
    const params: Record<string, string> = {};

    if (form.q) params.q = form.q;
    if (form.host) params.host = form.host;
    if (form.project) params.project = form.project;
    if (form.state !== 'open') params.state = form.state;
    if (!isDefaultSeverity.value) params.severity = form.severity.join(',');

    return params;
}

function apply() {
    router.visit(index({ query: query() }), {
        preserveState: true,
        preserveScroll: true,
        replace: true,
        only: ['findings', 'counts', 'filters'],
        onStart: () => (loading.value = true),
        onFinish: () => (loading.value = false),
    });
}

const applyTyped = useDebounceFn(apply, 300);

watch(() => [form.q, form.host], applyTyped);
watch(() => [form.project, form.state, form.severity.join()], apply);

function toggleSeverity(key: SeverityKey) {
    const selected = form.severity.includes(key);

    // At least one severity stays selected.
    if (selected && form.severity.length === 1) {
        return;
    }

    form.severity = selected
        ? form.severity.filter((s) => s !== key)
        : SEVERITIES.filter((s) => s === key || form.severity.includes(s));
}

function clearFilters() {
    Object.assign(form, {
        q: '',
        host: '',
        project: '',
        state: 'open',
        severity: [...DEFAULT_SEVERITIES],
    });
}

// Detail sheet for the clicked row, reusing the scan page's plugin view.
const sheetOpen = ref(false);
const selected = ref<FindingRow | null>(null);

function openFinding(finding: FindingRow) {
    if (!finding.project || finding.plugin_id === null) {
        return;
    }

    selected.value = finding;
    sheetOpen.value = true;
}
</script>

<template>
    <Head title="Findings" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Findings"
            description="Every finding across the projects you can see, one row per host and port."
        />

        <!-- Filters: one row that scopes everything below -->
        <div class="space-y-3">
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-4">
                <div class="relative">
                    <Search
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                    />
                    <Input
                        v-model="form.q"
                        type="search"
                        placeholder="Name, CVE or plugin ID"
                        class="pl-8"
                        aria-label="Search findings"
                    />
                </div>
                <div class="relative">
                    <Server
                        class="text-muted-foreground pointer-events-none absolute top-1/2 left-2.5 size-4 -translate-y-1/2"
                    />
                    <Input
                        v-model="form.host"
                        type="search"
                        placeholder="Host IP or name"
                        class="pl-8"
                        aria-label="Filter by host"
                    />
                </div>
                <NativeSelect
                    v-model="form.project"
                    :options="projectOptions"
                    aria-label="Project"
                />
                <NativeSelect
                    v-model="form.state"
                    :options="stateOptions"
                    aria-label="State"
                />
            </div>

            <div class="flex flex-wrap items-center gap-2">
                <button
                    v-for="key in SEVERITIES"
                    :key="key"
                    type="button"
                    :aria-pressed="form.severity.includes(key)"
                    :class="
                        cn(
                            'inline-flex items-center gap-1.5 rounded-full border px-3 py-1 text-xs font-medium transition-colors',
                            form.severity.includes(key)
                                ? [
                                      SEVERITY_STYLES[key].badge,
                                      'border-transparent',
                                  ]
                                : 'text-muted-foreground hover:bg-muted',
                        )
                    "
                    @click="toggleSeverity(key)"
                >
                    <span
                        v-if="!form.severity.includes(key)"
                        :class="
                            cn('size-1.5 rounded-full', SEVERITY_STYLES[key].bar)
                        "
                        aria-hidden="true"
                    />
                    {{ SEVERITY_STYLES[key].label }}
                    <span class="tabular-nums opacity-70">
                        {{ counts[key] }}
                    </span>
                </button>
                <button
                    v-if="hasFilters"
                    type="button"
                    class="text-muted-foreground hover:text-foreground px-1 text-xs underline-offset-2 hover:underline"
                    @click="clearFilters"
                >
                    Clear filters
                </button>
                <p
                    v-if="findings.total > 0"
                    class="text-muted-foreground text-xs sm:ml-auto"
                >
                    {{ findings.from }}–{{ findings.to }} of
                    {{ findings.total }}
                </p>
            </div>
        </div>

        <EmptyState
            v-if="findings.total === 0"
            :icon="Bug"
            :title="hasFilters ? 'Nothing matches' : 'No open findings'"
            :description="
                hasFilters
                    ? 'Try another search, or clear the filters.'
                    : 'Findings appear here once scans are imported into your projects.'
            "
        >
            <Button v-if="hasFilters" variant="outline" @click="clearFilters">
                Clear filters
            </Button>
        </EmptyState>

        <div
            v-else
            :class="
                cn(
                    'overflow-x-auto rounded-xl border transition-opacity',
                    loading && 'opacity-60',
                )
            "
        >
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-muted-foreground text-left">
                    <tr>
                        <th class="w-24 px-3 py-2 font-medium">Severity</th>
                        <th class="px-3 py-2 font-medium">Finding</th>
                        <th class="px-3 py-2 font-medium">Host</th>
                        <th class="px-3 py-2 font-medium">Port</th>
                        <th class="px-3 py-2 font-medium">Project</th>
                        <th class="px-3 py-2 font-medium">State</th>
                        <th class="px-3 py-2 font-medium whitespace-nowrap">
                            Last seen
                        </th>
                        <th class="w-8">
                            <span class="sr-only">Open</span>
                        </th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <tr
                        v-for="finding in findings.data"
                        :key="finding.id"
                        tabindex="0"
                        class="group hover:bg-muted/50 focus-visible:bg-muted/50 cursor-pointer outline-none"
                        @click="openFinding(finding)"
                        @keydown.enter="openFinding(finding)"
                    >
                        <td class="px-3 py-2">
                            <SeverityBadge :severity="finding.severity" />
                        </td>
                        <td class="max-w-md px-3 py-2">
                            <p class="font-medium">{{ finding.name }}</p>
                            <p class="text-muted-foreground font-mono text-xs">
                                {{ finding.plugin_id }}
                                <template v-if="finding.cve">
                                    · {{ finding.cve }}
                                    <template v-if="finding.cve_count > 1">
                                        +{{ finding.cve_count - 1 }}
                                    </template>
                                </template>
                                <template v-if="finding.cvss_score !== null">
                                    · CVSS {{ finding.cvss_score }}
                                </template>
                            </p>
                        </td>
                        <td class="px-3 py-2">
                            <p class="font-mono">{{ finding.ip_address }}</p>
                            <p
                                v-if="finding.hostname"
                                class="text-muted-foreground text-xs"
                            >
                                {{ finding.hostname }}
                            </p>
                        </td>
                        <td
                            class="text-muted-foreground px-3 py-2 font-mono whitespace-nowrap"
                        >
                            {{ finding.port }}/{{ finding.protocol }}
                        </td>
                        <td class="px-3 py-2">
                            <Link
                                v-if="finding.project"
                                :href="showProject(finding.project.id)"
                                :title="finding.project.name"
                                @click.stop
                            >
                                <Badge variant="outline" class="font-mono">
                                    {{ finding.project.code }}
                                </Badge>
                            </Link>
                        </td>
                        <td class="px-3 py-2 whitespace-nowrap">
                            {{ titleCase(finding.state) }}
                        </td>
                        <td
                            class="text-muted-foreground px-3 py-2 whitespace-nowrap"
                            :title="formatDateTime(finding.last_found_at)"
                        >
                            {{ formatRelative(finding.last_found_at) }}
                        </td>
                        <td class="pr-3">
                            <ChevronRight
                                class="text-muted-foreground size-4 transition-transform group-hover:translate-x-0.5"
                                aria-hidden="true"
                            />
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <nav
            v-if="findings.last_page > 1"
            class="flex items-center justify-between gap-2"
            aria-label="Pagination"
        >
            <Button
                v-if="findings.prev_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="findings.prev_page_url" preserve-state>
                    <ChevronLeft /> Previous
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                <ChevronLeft /> Previous
            </Button>
            <span class="text-muted-foreground text-sm">
                Page {{ findings.current_page }} of {{ findings.last_page }}
            </span>
            <Button
                v-if="findings.next_page_url"
                variant="outline"
                size="sm"
                as-child
            >
                <Link :href="findings.next_page_url" preserve-state>
                    Next <ChevronRight />
                </Link>
            </Button>
            <Button v-else variant="outline" size="sm" disabled>
                Next <ChevronRight />
            </Button>
        </nav>

        <PluginDetailSheet
            v-if="selected?.project && selected.plugin_id !== null"
            v-model:open="sheetOpen"
            :project-id="selected.project.id"
            :scan-id="selected.scan_id"
            :plugin-id="selected.plugin_id"
        />
    </div>
</template>
