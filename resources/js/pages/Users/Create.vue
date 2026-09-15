<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/UserController';
import Heading from '@/components/Heading.vue';
import UserForm from '@/components/vapt/UserForm.vue';
import { create, index } from '@/routes/users';
import type { ProjectRole, UserProjectOption } from '@/types';

defineProps<{
    projects: UserProjectOption[];
    projectRoles: ProjectRole[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Users', href: index() },
            { title: 'New user', href: create() },
        ],
    },
});
</script>

<template>
    <Head title="New user" />

    <div class="flex flex-1 flex-col p-4">
        <Heading
            title="New user"
            description="Public sign-up is off, so accounts are created here. The email counts as verified."
        />
        <UserForm
            :action="UserController.store()"
            :projects="projects"
            :project-roles="projectRoles"
        />
    </div>
</template>
