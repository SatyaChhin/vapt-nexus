<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import { ShieldCheck } from '@lucide/vue';
import { computed, reactive } from 'vue';
import InputError from '@/components/InputError.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { titleCase } from '@/lib/format';
import { index } from '@/routes/users';
import type { ProjectRole, UserProjectOption } from '@/types';
import type { RouteDefinition } from '@/wayfinder';
import NativeSelect from './NativeSelect.vue';

const props = defineProps<{
    action: RouteDefinition<'post' | 'put' | 'patch'>;
    user?: {
        name: string;
        email: string;
        role: 'admin' | 'member';
        is_self: boolean;
        projects: { id: number; role: ProjectRole }[];
    };
    projects: UserProjectOption[];
    projectRoles: ProjectRole[];
}>();

const editing = !!props.user;

const ROLE_HELP: Record<ProjectRole, string> = {
    manager: 'Edits the project, imports scans, deletes reports',
    analyst: 'Imports scans and works on findings',
    viewer: 'Read-only: results and reports',
};

const form = useForm({
    name: props.user?.name ?? '',
    email: props.user?.email ?? '',
    role: (props.user?.role ?? 'member') as string,
    password: '',
    password_confirmation: '',
    projects: [] as { id: number; role: ProjectRole }[],
});

/** Project id → role, or '' for no access. */
const access = reactive<Record<number, ProjectRole | ''>>(
    Object.fromEntries(
        props.projects.map((project) => [
            project.id,
            props.user?.projects.find((p) => p.id === project.id)?.role ?? '',
        ]),
    ),
);

const roleOptions = [
    { value: 'member', label: 'Member: only the projects given below' },
    { value: 'admin', label: 'Admin: every project, servers and users' },
];

const accessOptions = computed(() => [
    { value: '', label: 'No access' },
    ...props.projectRoles.map((role) => ({
        value: role,
        label: titleCase(role),
    })),
]);

const grantedCount = computed(
    () => Object.values(access).filter((role) => role !== '').length,
);

const projectsError = computed(
    () =>
        Object.entries(form.errors).find(([key]) =>
            key.startsWith('projects'),
        )?.[1],
);

function submit() {
    form.transform((data) => ({
        ...data,
        projects: Object.entries(access)
            .filter(([, role]) => role !== '')
            .map(([id, role]) => ({ id: Number(id), role })),
    })).submit(props.action, {
        preserveScroll: true,
        // Never keep passwords in page state after a round trip.
        onFinish: () => form.reset('password', 'password_confirmation'),
    });
}
</script>

<template>
    <form class="max-w-2xl space-y-6" @submit.prevent="submit">
        <div class="grid gap-4 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    maxlength="255"
                    autocomplete="off"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    v-model="form.email"
                    type="email"
                    required
                    maxlength="255"
                    autocomplete="off"
                />
                <InputError :message="form.errors.email" />
            </div>
        </div>

        <div class="grid gap-2">
            <Label for="role">Role</Label>
            <NativeSelect
                id="role"
                v-model="form.role"
                :options="roleOptions"
                :disabled="user?.is_self"
                :invalid="!!form.errors.role"
            />
            <p v-if="user?.is_self" class="text-muted-foreground text-xs">
                You can't change your own role. Another admin can.
            </p>
            <InputError :message="form.errors.role" />
        </div>

        <div class="grid gap-4 rounded-lg border p-4">
            <div class="text-sm">
                <p class="font-medium">Password</p>
                <p class="text-muted-foreground">
                    At least 12 characters.
                    <template v-if="editing">
                        Leave both blank to keep the current password. A new
                        password signs the user out everywhere.
                    </template>
                    <template v-else>
                        Share it with the user securely; they can change it
                        under Settings.
                    </template>
                </p>
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <div class="grid gap-2">
                    <Label for="password">
                        {{ editing ? 'New password' : 'Password' }}
                    </Label>
                    <PasswordInput
                        id="password"
                        v-model="form.password"
                        :required="!editing"
                        autocomplete="new-password"
                    />
                    <InputError :message="form.errors.password" />
                </div>
                <div class="grid gap-2">
                    <Label for="password_confirmation">Confirm password</Label>
                    <PasswordInput
                        id="password_confirmation"
                        v-model="form.password_confirmation"
                        :required="!editing || form.password !== ''"
                        autocomplete="new-password"
                    />
                </div>
            </div>
        </div>

        <section class="space-y-3">
            <div>
                <h3 class="text-sm font-medium">
                    Project access
                    <span class="text-muted-foreground font-normal">
                        ({{ grantedCount }} of {{ projects.length }})
                    </span>
                </h3>
                <p class="text-muted-foreground text-xs">
                    <template
                        v-for="(help, role) in ROLE_HELP"
                        :key="role"
                    >
                        <span class="text-foreground font-medium">{{
                            titleCase(role)
                        }}</span>
                        {{ help }}.
                    </template>
                </p>
            </div>

            <Alert v-if="form.role === 'admin'">
                <ShieldCheck class="size-4" />
                <AlertTitle>Admins see every project</AlertTitle>
                <AlertDescription>
                    The access below only matters if the role changes back to
                    Member.
                </AlertDescription>
            </Alert>

            <p
                v-if="projects.length === 0"
                class="text-muted-foreground rounded-lg border border-dashed p-4 text-sm"
            >
                No projects yet.
            </p>
            <ul v-else class="divide-y rounded-lg border">
                <li
                    v-for="project in projects"
                    :key="project.id"
                    class="flex flex-wrap items-center justify-between gap-3 px-3 py-2"
                >
                    <div class="min-w-0 text-sm">
                        <span class="font-medium">{{ project.name }}</span>
                        <span class="text-muted-foreground font-mono text-xs">
                            {{ project.code }}
                        </span>
                        <Badge
                            v-if="project.status === 'archived'"
                            variant="secondary"
                            class="ml-2"
                        >
                            Archived
                        </Badge>
                    </div>
                    <NativeSelect
                        v-model="access[project.id]"
                        :options="accessOptions"
                        class="w-36"
                        :aria-label="`Access to ${project.name}`"
                    />
                </li>
            </ul>
            <InputError :message="projectsError" />
        </section>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                {{ editing ? 'Save changes' : 'Create user' }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
