<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { CloudDownload, RefreshCw, TriangleAlert } from '@lucide/vue';
import { reactive, ref, watch } from 'vue';
import { toast } from 'vue-sonner';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Spinner } from '@/components/ui/spinner';
import { ApiError, apiRequest } from '@/lib/api';
import { formatDateTime, titleCase } from '@/lib/format';
import { nessusScans } from '@/routes/api/projects';
import { importMethod, sync } from '@/routes/api/projects/scans';
import type { NessusScanOption, NessusServerScans } from '@/types';

const props = defineProps<{ projectId: number }>();

const open = ref(false);
const loading = ref(false);
const loadError = ref<string | null>(null);
const servers = ref<NessusServerScans[]>([]);
const busy = reactive<Record<string, boolean>>({});

async function load() {
    loading.value = true;
    loadError.value = null;

    try {
        const response = await apiRequest<{ data: NessusServerScans[] }>(
            nessusScans(props.projectId),
        );
        servers.value = response.data;
    } catch (error) {
        loadError.value =
            error instanceof ApiError
                ? error.message
                : 'Could not load the scan list.';
    } finally {
        loading.value = false;
    }
}

watch(open, (isOpen) => {
    if (isOpen) {
        load();
    }
});

async function importScan(server: NessusServerScans, scan: NessusScanOption) {
    const key = `${server.id}:${scan.id}`;
    busy[key] = true;

    try {
        await apiRequest(
            scan.scan_id
                ? sync({ project: props.projectId, scan: scan.scan_id })
                : importMethod(props.projectId),
            scan.scan_id
                ? undefined
                : { nessus_server_id: server.id, nessus_scan_id: scan.id },
        );
        toast.success(
            `Importing “${scan.name}”. Results appear as soon as it finishes.`,
        );
        open.value = false;
        router.reload();
    } catch (error) {
        toast.error(
            error instanceof ApiError ? error.message : 'The import failed.',
        );
    } finally {
        busy[key] = false;
    }
}

const importing = (status: string | null) =>
    status === 'queued' || status === 'importing';
</script>

<template>
    <Dialog v-model:open="open">
        <DialogTrigger as-child>
            <Button><CloudDownload /> Import from Nessus</Button>
        </DialogTrigger>
        <DialogContent class="max-h-[85vh] overflow-y-auto sm:max-w-2xl">
            <DialogHeader>
                <DialogTitle>Import scans from Nessus</DialogTitle>
                <DialogDescription>
                    Create and launch scans in the Nessus web UI, then import
                    their results here. Tip: a scan whose name contains the
                    project code as a separate word (e.g.
                    <code>VA_POS</code> for POS) is imported automatically, and
                    its PDF report is generated when it finishes.
                </DialogDescription>
            </DialogHeader>

            <div
                v-if="loading"
                class="text-muted-foreground flex items-center gap-2 py-6 text-sm"
            >
                <Spinner /> Loading scans from Nessus…
            </div>

            <p
                v-else-if="loadError"
                class="text-sm text-red-700 dark:text-red-400"
            >
                {{ loadError }}
            </p>

            <p
                v-else-if="servers.length === 0"
                class="text-muted-foreground text-sm"
            >
                No Nessus server is assigned to this project. An administrator
                can assign one on the project's edit page.
            </p>

            <div v-else class="space-y-5">
                <section
                    v-for="server in servers"
                    :key="server.id"
                    class="space-y-2"
                >
                    <h3 class="text-sm font-medium">{{ server.name }}</h3>

                    <p
                        v-if="server.error"
                        class="flex items-start gap-2 rounded-lg border border-red-600/30 bg-red-500/5 p-3 text-sm text-red-800 dark:text-red-300"
                    >
                        <TriangleAlert class="mt-0.5 size-4 shrink-0" />
                        {{ server.error }}
                    </p>

                    <p
                        v-else-if="server.scans.length === 0"
                        class="text-muted-foreground text-sm"
                    >
                        This server has no scans yet.
                    </p>

                    <ul v-else class="divide-y rounded-xl border">
                        <li
                            v-for="scan in server.scans"
                            :key="scan.id"
                            :class="[
                                'flex flex-wrap items-center justify-between gap-3 p-3',
                                scan.in_trash && 'opacity-70',
                            ]"
                        >
                            <div class="min-w-0">
                                <p class="truncate font-medium">
                                    {{ scan.name }}
                                </p>
                                <p class="text-muted-foreground text-xs">
                                    {{ titleCase(scan.status) }} ·
                                    {{ scan.folder ?? 'No folder' }} · updated
                                    {{ formatDateTime(scan.last_modified_at) }}
                                </p>
                            </div>

                            <div class="flex items-center gap-2">
                                <Badge
                                    v-if="scan.imported_elsewhere"
                                    variant="secondary"
                                >
                                    In another project
                                </Badge>
                                <template v-else>
                                    <Badge
                                        v-if="scan.scan_id"
                                        variant="outline"
                                    >
                                        Imported
                                    </Badge>
                                    <Button
                                        size="sm"
                                        :variant="
                                            scan.scan_id ? 'outline' : 'default'
                                        "
                                        :disabled="
                                            busy[`${server.id}:${scan.id}`] ||
                                            importing(scan.scan_status) ||
                                            scan.status === 'empty'
                                        "
                                        @click="importScan(server, scan)"
                                    >
                                        <Spinner
                                            v-if="
                                                busy[`${server.id}:${scan.id}`]
                                            "
                                        />
                                        <RefreshCw v-else-if="scan.scan_id" />
                                        <CloudDownload v-else />
                                        {{
                                            importing(scan.scan_status)
                                                ? 'Importing…'
                                                : scan.scan_id
                                                  ? 'Re-sync'
                                                  : 'Import'
                                        }}
                                    </Button>
                                </template>
                            </div>
                        </li>
                    </ul>
                </section>
            </div>
        </DialogContent>
    </Dialog>
</template>
