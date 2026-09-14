<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import ProjectController from '@/actions/App/Http/Controllers/ProjectController';
import Heading from '@/components/Heading.vue';
import ProjectForm from '@/components/vapt/ProjectForm.vue';
import { create, index } from '@/routes/projects';
import type { NessusServerOption } from '@/types';

defineProps<{ nessusServers: NessusServerOption[] }>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Projects', href: index() },
            { title: 'New', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New project" />

    <div class="flex flex-1 flex-col p-4">
        <Heading
            title="New project"
            description="You become the project manager. Scans and findings are always kept inside their project."
        />
        <ProjectForm
            :action="ProjectController.store()"
            :nessus-servers="nessusServers"
        />
    </div>
</template>
