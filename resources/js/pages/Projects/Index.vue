<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { FolderKanban, Plus } from '@lucide/vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import EmptyState from '@/components/vapt/EmptyState.vue';
import ProjectCard from '@/components/vapt/ProjectCard.vue';
import { create, index } from '@/routes/projects';
import type { Project } from '@/types';

defineProps<{
    projects: Project[];
    can: { create: boolean };
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Projects', href: index() }],
    },
});
</script>

<template>
    <Head title="Projects" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Projects"
                description="Each project keeps its scans, findings, raw Nessus results and reports separate."
            />
            <Button v-if="can.create" as-child>
                <Link :href="create()"><Plus /> New project</Link>
            </Button>
        </div>

        <EmptyState
            v-if="projects.length === 0"
            :icon="FolderKanban"
            title="No projects yet"
            :description="
                can.create
                    ? 'Create a project such as Healthcare (HC) or KHMER POS (POS) to start organising scans.'
                    : 'Ask an administrator to add you to a project.'
            "
        >
            <Button v-if="can.create" as-child>
                <Link :href="create()"><Plus /> New project</Link>
            </Button>
        </EmptyState>

        <div v-else class="grid gap-4 sm:grid-cols-2 xl:grid-cols-3">
            <ProjectCard
                v-for="project in projects"
                :key="project.id"
                :project="project"
            />
        </div>
    </div>
</template>
