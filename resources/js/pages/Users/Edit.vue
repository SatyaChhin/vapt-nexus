<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import UserController from '@/actions/App/Http/Controllers/UserController';
import Heading from '@/components/Heading.vue';
import UserForm from '@/components/vapt/UserForm.vue';
import { edit, index } from '@/routes/users';
import type { ProjectRole, UserProjectOption } from '@/types';

const props = defineProps<{
    user: {
        id: number;
        name: string;
        email: string;
        role: 'admin' | 'member';
        disabled_at: string | null;
        is_self: boolean;
        projects: { id: number; role: ProjectRole }[];
    };
    projects: UserProjectOption[];
    projectRoles: ProjectRole[];
}>();

setLayoutProps({
    breadcrumbs: [
        { title: 'Users', href: index() },
        { title: props.user.name, href: edit(props.user.id) },
    ],
});
</script>

<template>
    <Head :title="`Edit ${user.name}`" />

    <div class="flex flex-1 flex-col p-4">
        <Heading
            :title="`Edit ${user.name}`"
            :description="
                user.disabled_at
                    ? 'This account is disabled. Enable it from the Users list.'
                    : 'Changes apply on the user\'s next page load.'
            "
        />
        <UserForm
            :action="UserController.update(user.id)"
            :user="user"
            :projects="projects"
            :project-roles="projectRoles"
        />
    </div>
</template>
