<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Crosshair, FolderKanban, Plus, ServerCog } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/vapt/EmptyState.vue';
import NessusStatusBadge from '@/components/vapt/NessusStatusBadge.vue';
import ProjectCard from '@/components/vapt/ProjectCard.vue';
import SeveritySummary from '@/components/vapt/SeveritySummary.vue';
import StatCard from '@/components/vapt/StatCard.vue';
import { dashboard } from '@/routes';
import { index as nessusServers } from '@/routes/nessus-servers';
import {
    create as createProject,
    index as projectsIndex,
} from '@/routes/projects';
import type { NessusServerStatus, Project, SeverityCounts } from '@/types';

defineProps<{
    stats: {
        projects: number;
        scans: number;
        servers_total: number | null;
        servers_connected: number | null;
        severity: SeverityCounts;
    };
    projects: Project[];
    servers:
        | {
              id: number;
              name: string;
              status: NessusServerStatus;
              last_checked_at: string | null;
          }[]
        | null;
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Dashboard', href: dashboard() }],
    },
});

const page = usePage();
</script>

<template>
    <Head title="Dashboard" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <Heading
            title="Dashboard"
            description="Overview of every project you have access to."
        />

        <div class="grid gap-4 sm:grid-cols-3">
            <StatCard
                label="Projects"
                :value="stats.projects"
                :icon="FolderKanban"
            />
            <StatCard label="Scans" :value="stats.scans" :icon="Crosshair" />
            <StatCard
                v-if="stats.servers_total !== null"
                label="Nessus servers connected"
                :value="`${stats.servers_connected} / ${stats.servers_total}`"
                :icon="ServerCog"
            />
        </div>

        <SeveritySummary :counts="stats.severity" />

        <section v-if="servers !== null" class="space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium">Nessus servers</h3>
                <Link
                    :href="nessusServers()"
                    class="text-muted-foreground text-sm hover:underline"
                    >Manage</Link
                >
            </div>
            <EmptyState
                v-if="servers.length === 0"
                :icon="ServerCog"
                title="Connect your Nessus scanner"
                description="Add the Nessus instance running in VMware and run Test Connection."
            >
                <Button as-child>
                    <Link :href="nessusServers()"
                        ><Plus /> Add Nessus server</Link
                    >
                </Button>
            </EmptyState>
            <ul v-else class="flex flex-wrap gap-2">
                <li
                    v-for="server in servers"
                    :key="server.id"
                    class="flex items-center gap-2 rounded-lg border px-3 py-2 text-sm"
                >
                    {{ server.name }}
                    <NessusStatusBadge :status="server.status" />
                </li>
            </ul>
        </section>

        <section class="space-y-3">
            <div class="flex items-center justify-between">
                <h3 class="text-sm font-medium">Projects</h3>
                <Link
                    :href="projectsIndex()"
                    class="text-muted-foreground text-sm hover:underline"
                    >View all</Link
                >
            </div>
            <EmptyState
                v-if="projects.length === 0"
                :icon="FolderKanban"
                title="No projects yet"
            >
                <Button v-if="page.props.auth.can.createProjects" as-child>
                    <Link :href="createProject()"><Plus /> New project</Link>
                </Button>
            </EmptyState>
            <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
                <ProjectCard
                    v-for="project in projects"
                    :key="project.id"
                    :project="project"
                />
            </div>
        </section>
    </div>
</template>
