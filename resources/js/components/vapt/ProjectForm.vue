<script setup lang="ts">
import { Link, useForm } from '@inertiajs/vue3';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index, show } from '@/routes/projects';
import type { NessusServerOption, Project } from '@/types';
import type { RouteDefinition } from '@/wayfinder';
import NativeSelect from './NativeSelect.vue';
import NessusStatusBadge from './NessusStatusBadge.vue';

const props = defineProps<{
    action: RouteDefinition<'post' | 'put' | 'patch'>;
    project?: Project;
    nessusServers: NessusServerOption[];
}>();

const editing = !!props.project;
const canAssignServers = props.nessusServers.length > 0;

const form = useForm({
    code: props.project?.code ?? '',
    name: props.project?.name ?? '',
    description: props.project?.description ?? '',
    environment: props.project?.environment ?? 'lab',
    status: props.project?.status ?? 'active',
    nessus_server_ids: (props.project?.nessus_servers ?? []).map((s) => s.id),
});

const environments = [
    { value: 'lab', label: 'Lab' },
    { value: 'development', label: 'Development' },
    { value: 'staging', label: 'Staging' },
    { value: 'production', label: 'Production' },
];

const statuses = [
    { value: 'active', label: 'Active' },
    { value: 'on_hold', label: 'On hold' },
    { value: 'archived', label: 'Archived' },
];

function toggleServer(id: number, checked: boolean) {
    form.nessus_server_ids = checked
        ? [...form.nessus_server_ids, id]
        : form.nessus_server_ids.filter((existing) => existing !== id);
}

function submit() {
    form.transform((data) => {
        const payload: Record<string, unknown> = { ...data };

        // The code is immutable after creation; server assignment is admin-only.
        if (editing) {
            delete payload.code;
        }

        if (!canAssignServers) {
            delete payload.nessus_server_ids;
        }

        return payload;
    }).submit(props.action, { preserveScroll: true });
}
</script>

<template>
    <form class="max-w-2xl space-y-6" @submit.prevent="submit">
        <div class="grid gap-6 sm:grid-cols-[10rem_1fr]">
            <div class="grid gap-2">
                <Label for="code">Code</Label>
                <Input
                    id="code"
                    v-model="form.code"
                    :disabled="editing"
                    required
                    maxlength="10"
                    placeholder="HC"
                    class="font-mono uppercase"
                    autocomplete="off"
                />
                <InputError :message="form.errors.code" />
            </div>

            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    maxlength="150"
                    placeholder="Healthcare"
                />
                <InputError :message="form.errors.name" />
            </div>
        </div>
        <p class="text-muted-foreground -mt-4 text-xs">
            The code (2-10 letters or digits) is used in storage paths and
            report numbers such as <code>VULN-HC-2026-00001</code>, so it cannot
            be changed later.
        </p>

        <div class="grid gap-2">
            <Label for="description">Description</Label>
            <textarea
                id="description"
                v-model="form.description"
                rows="3"
                maxlength="2000"
                class="border-input dark:bg-input/30 focus-visible:border-ring focus-visible:ring-ring/50 w-full rounded-md border bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] md:text-sm"
            />
            <InputError :message="form.errors.description" />
        </div>

        <div class="grid gap-6 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label for="environment">Environment</Label>
                <NativeSelect
                    id="environment"
                    v-model="form.environment"
                    :options="environments"
                    :invalid="!!form.errors.environment"
                />
                <InputError :message="form.errors.environment" />
            </div>

            <div class="grid gap-2">
                <Label for="status">Status</Label>
                <NativeSelect
                    id="status"
                    v-model="form.status"
                    :options="statuses"
                    :invalid="!!form.errors.status"
                />
                <InputError :message="form.errors.status" />
            </div>
        </div>

        <fieldset v-if="canAssignServers" class="grid gap-3">
            <legend class="mb-2 text-sm font-medium">Nessus servers</legend>
            <label
                v-for="server in nessusServers"
                :key="server.id"
                class="flex items-center gap-3 rounded-md border px-3 py-2"
            >
                <Checkbox
                    :model-value="form.nessus_server_ids.includes(server.id)"
                    @update:model-value="
                        (value) => toggleServer(server.id, value === true)
                    "
                />
                <span class="flex-1 text-sm">{{ server.name }}</span>
                <NessusStatusBadge :status="server.status" />
            </label>
            <InputError :message="form.errors.nessus_server_ids" />
        </fieldset>

        <div class="flex items-center gap-3">
            <Button type="submit" :disabled="form.processing">
                {{ editing ? 'Save changes' : 'Create project' }}
            </Button>
            <Button variant="ghost" as-child>
                <Link :href="project ? show(project.id) : index()">Cancel</Link>
            </Button>
        </div>
    </form>
</template>
