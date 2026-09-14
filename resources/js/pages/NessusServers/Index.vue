<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';
import { CircleCheck, CircleX, Plug, Plus, Server } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import ConfirmDeleteButton from '@/components/vapt/ConfirmDeleteButton.vue';
import EmptyState from '@/components/vapt/EmptyState.vue';
import NessusStatusBadge from '@/components/vapt/NessusStatusBadge.vue';
import { ApiError, apiRequest } from '@/lib/api';
import { formatDateTime, formatRelative } from '@/lib/format';
import { test } from '@/routes/api/nessus/servers';
import { create, destroy, edit, index } from '@/routes/nessus-servers';
import type { ConnectionTestResult, NessusServer } from '@/types';

const props = defineProps<{ servers: NessusServer[] }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Nessus Servers', href: index() }],
    },
});

const servers = ref<NessusServer[]>(props.servers);
watch(
    () => props.servers,
    (value) => (servers.value = value),
);
const testing = reactive<Record<number, boolean>>({});
const results = reactive<Record<number, ConnectionTestResult>>({});

async function testConnection(server: NessusServer) {
    testing[server.id] = true;
    delete results[server.id];

    try {
        const result = await apiRequest<ConnectionTestResult>(test(server.id));
        results[server.id] = result;

        if (result.server) {
            const i = servers.value.findIndex((s) => s.id === server.id);
            servers.value[i] = result.server;
        }
    } catch (error) {
        results[server.id] = {
            success: false,
            message:
                error instanceof ApiError
                    ? error.message
                    : 'Unable to connect to Nessus',
            status: 'failed',
            nessus_status: null,
            version: null,
            edition: null,
            latency_ms: null,
        };
    } finally {
        testing[server.id] = false;
    }
}
</script>

<template>
    <Head title="Nessus Servers" />

    <div class="flex flex-1 flex-col gap-6 p-4">
        <div class="flex flex-wrap items-start justify-between gap-4">
            <Heading
                title="Nessus Servers"
                description="Scanners Laravel connects to. The browser never talks to Nessus directly and API keys never leave the server."
            />
            <Button as-child>
                <Link :href="create()"><Plus /> Add Nessus server</Link>
            </Button>
        </div>

        <EmptyState
            v-if="servers.length === 0"
            :icon="Server"
            title="No Nessus server yet"
            description="Add the Nessus instance running in your VMware lab, e.g. https://192.168.56.10:8834, with an API key pair generated in Nessus."
        >
            <Button as-child>
                <Link :href="create()"><Plus /> Add Nessus server</Link>
            </Button>
        </EmptyState>

        <div v-else class="grid gap-4">
            <article
                v-for="server in servers"
                :key="server.id"
                class="rounded-xl border p-4"
            >
                <div class="flex flex-wrap items-start justify-between gap-4">
                    <div class="min-w-0 space-y-1">
                        <div class="flex flex-wrap items-center gap-2">
                            <h3 class="font-semibold">{{ server.name }}</h3>
                            <NessusStatusBadge :status="server.status" />
                        </div>
                        <p class="font-mono text-sm break-all">
                            {{ server.base_url }}
                        </p>
                    </div>

                    <div class="flex flex-wrap items-center gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            :disabled="testing[server.id]"
                            @click="testConnection(server)"
                        >
                            <Spinner v-if="testing[server.id]" />
                            <Plug v-else />
                            Test Connection
                        </Button>
                        <Button variant="ghost" size="sm" as-child>
                            <Link :href="edit(server.id)">Edit</Link>
                        </Button>
                        <ConfirmDeleteButton
                            :action="destroy(server.id)"
                            :title="`Delete ${server.name}?`"
                            description="Its API keys are removed. Existing scans keep their history but lose the link to this server."
                        />
                    </div>
                </div>

                <dl
                    class="text-muted-foreground mt-4 grid gap-x-6 gap-y-2 text-sm sm:grid-cols-2 lg:grid-cols-4"
                >
                    <div>
                        <dt class="text-xs">SSL verification</dt>
                        <dd class="text-foreground">
                            {{
                                server.verify_ssl ? 'Enabled' : 'Disabled (lab)'
                            }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs">Version</dt>
                        <dd class="text-foreground">
                            {{ server.server_version ?? '—' }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs">Last connected</dt>
                        <dd
                            class="text-foreground"
                            :title="formatDateTime(server.last_connected_at)"
                        >
                            {{ formatRelative(server.last_connected_at) }}
                        </dd>
                    </div>
                    <div>
                        <dt class="text-xs">Projects</dt>
                        <dd class="text-foreground">
                            {{ server.projects_count ?? 0 }}
                        </dd>
                    </div>
                </dl>

                <div
                    v-if="results[server.id]"
                    role="status"
                    :class="[
                        'mt-4 flex items-start gap-2 rounded-lg border p-3 text-sm',
                        results[server.id].success
                            ? 'border-emerald-600/30 bg-emerald-500/5 text-emerald-800 dark:text-emerald-300'
                            : 'border-red-600/30 bg-red-500/5 text-red-800 dark:text-red-300',
                    ]"
                >
                    <CircleCheck
                        v-if="results[server.id].success"
                        class="mt-0.5 size-4 shrink-0"
                    />
                    <CircleX v-else class="mt-0.5 size-4 shrink-0" />
                    <div>
                        <p>{{ results[server.id].message }}</p>
                        <p
                            v-if="results[server.id].latency_ms !== null"
                            class="mt-1 text-xs opacity-80"
                        >
                            {{ results[server.id].latency_ms }} ms
                            <template v-if="results[server.id].edition">
                                · {{ results[server.id].edition }}
                            </template>
                            <template v-if="results[server.id].version">
                                {{ results[server.id].version }}
                            </template>
                        </p>
                    </div>
                </div>
                <p
                    v-else-if="
                        server.last_error && server.status !== 'connected'
                    "
                    class="mt-4 text-sm text-red-700 dark:text-red-400"
                >
                    {{ server.last_error }}
                </p>
            </article>
        </div>
    </div>
</template>
