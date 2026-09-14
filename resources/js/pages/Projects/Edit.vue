<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import Heading from '@/components/Heading.vue';
import ProjectForm from '@/components/vapt/ProjectForm.vue';
import { edit, index, show } from '@/routes/projects';
import type { NessusServerOption, Project } from '@/types';

const props = defineProps<{
    project: Project;
    nessusServers: NessusServerOption[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Projects', href: index() },
        { title: props.project.name, href: show(props.project.id) },
        { title: 'Edit', href: edit(props.project.id) },
    ],
});
</script>

<template>
    <Head :title="`Edit ${project.name}`" />

    <div class="flex flex-1 flex-col p-4">
        <Heading :title="`Edit ${project.name}`" />
        <ProjectForm
            :action="ProjectController.update(project.id)"
            :project="project"
            :nessus-servers="nessusServers"
        />
    </div>
</template>
