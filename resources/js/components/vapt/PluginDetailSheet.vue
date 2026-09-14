<script setup lang="ts">
import { ExternalLink } from '@lucide/vue';
import { ref, watch } from 'vue';
import { Badge } from '@/components/ui/badge';
import {
    Sheet,
    SheetContent,
    SheetDescription,
    SheetHeader,
    SheetTitle,
} from '@/components/ui/sheet';
import { Spinner } from '@/components/ui/spinner';
import { ApiError, apiRequest } from '@/lib/api';
import { plugin as pluginRoute } from '@/routes/api/projects/scans';
import type { PluginDetail } from '@/types';
import SeverityBadge from './SeverityBadge.vue';

const props = defineProps<{
    projectId: number;
    scanId: number;
    pluginId: number | null;
}>();

const open = defineModel<boolean>('open', { required: true });

const detail = ref<PluginDetail | null>(null);
const loading = ref(false);
const error = ref<string | null>(null);

watch(
    () => [props.pluginId, open.value] as const,
    async ([pluginId, isOpen]) => {
        if (
            !isOpen ||
            pluginId === null ||
            detail.value?.plugin_id === pluginId
        ) {
            return;
        }

        loading.value = true;
        error.value = null;
        detail.value = null;

        try {
            const response = await apiRequest<{ data: PluginDetail }>(
                pluginRoute({
                    project: props.projectId,
                    scan: props.scanId,
                    plugin: pluginId,
                }),
            );
            detail.value = response.data;
        } catch (e) {
            error.value =
                e instanceof ApiError
                    ? e.message
                    : 'Could not load the finding.';
        } finally {
            loading.value = false;
        }
    },
);

function isUrl(value: string): boolean {
    return /^https?:\/\//i.test(value);
}
</script>

<template>
    <Sheet v-model:open="open">
        <SheetContent class="w-full overflow-y-auto sm:max-w-2xl">
            <div
                v-if="loading"
                class="text-muted-foreground flex items-center gap-2 p-6 text-sm"
            >
                <Spinner /> Loading…
            </div>

            <p
                v-else-if="error"
                class="p-6 text-sm text-red-700 dark:text-red-400"
            >
                {{ error }}
            </p>

            <template v-else-if="detail">
                <SheetHeader class="pr-10">
                    <div class="flex flex-wrap items-center gap-2">
                        <SeverityBadge :severity="detail.severity" />
                        <span class="text-muted-foreground font-mono text-xs">
                            Plugin {{ detail.plugin_id }}
                        </span>
                    </div>
                    <SheetTitle class="text-lg">{{ detail.name }}</SheetTitle>
                    <SheetDescription v-if="detail.synopsis">
                        {{ detail.synopsis }}
                    </SheetDescription>
                </SheetHeader>

                <div class="space-y-6 px-4 pb-6 text-sm">
                    <dl class="grid gap-3 sm:grid-cols-2">
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                CVSS
                                {{
                                    detail.cvss_version
                                        ? `v${detail.cvss_version}`
                                        : ''
                                }}
                            </dt>
                            <dd class="font-medium">
                                {{ detail.cvss_score ?? '—' }}
                            </dd>
                            <dd
                                v-if="detail.cvss_vector"
                                class="text-muted-foreground font-mono text-xs break-all"
                            >
                                {{ detail.cvss_vector }}
                            </dd>
                        </div>
                        <div>
                            <dt class="text-muted-foreground text-xs">
                                Family
                            </dt>
                            <dd class="font-medium">
                                {{ detail.family ?? '—' }}
                            </dd>
                        </div>
                    </dl>

                    <div v-if="detail.cve.length" class="space-y-2">
                        <h4 class="font-medium">CVE</h4>
                        <div class="flex flex-wrap gap-1">
                            <Badge
                                v-for="cve in detail.cve"
                                :key="cve"
                                variant="outline"
                                class="font-mono"
                            >
                                {{ cve }}
                            </Badge>
                        </div>
                    </div>

                    <div v-if="detail.description" class="space-y-2">
                        <h4 class="font-medium">Description</h4>
                        <p class="text-muted-foreground whitespace-pre-line">
                            {{ detail.description }}
                        </p>
                    </div>

                    <div v-if="detail.solution" class="space-y-2">
                        <h4 class="font-medium">Solution</h4>
                        <p class="whitespace-pre-line">{{ detail.solution }}</p>
                    </div>

                    <div class="space-y-2">
                        <h4 class="font-medium">
                            Affected
                            <span class="text-muted-foreground font-normal">
                                ({{ detail.instances.length }})
                            </span>
                        </h4>
                        <details
                            v-for="instance in detail.instances"
                            :key="instance.id"
                            class="rounded-lg border"
                        >
                            <summary
                                class="flex cursor-pointer flex-wrap items-center gap-2 px-3 py-2"
                            >
                                <span class="font-mono">
                                    {{ instance.ip_address }}
                                </span>
                                <span class="text-muted-foreground font-mono">
                                    {{ instance.port }}/{{ instance.protocol }}
                                </span>
                                <Badge
                                    v-if="instance.service"
                                    variant="secondary"
                                >
                                    {{ instance.service }}
                                </Badge>
                            </summary>
                            <pre
                                class="bg-muted/50 max-h-96 overflow-auto border-t p-3 font-mono text-xs whitespace-pre-wrap"
                                >{{
                                    instance.plugin_output?.trim() ||
                                    'Nessus reported no output for this port.'
                                }}</pre>
                        </details>
                    </div>

                    <div v-if="detail.see_also.length" class="space-y-2">
                        <h4 class="font-medium">See also</h4>
                        <ul class="space-y-1">
                            <li v-for="link in detail.see_also" :key="link">
                                <a
                                    v-if="isUrl(link)"
                                    :href="link"
                                    target="_blank"
                                    rel="noopener noreferrer"
                                    class="inline-flex items-center gap-1 break-all underline underline-offset-2"
                                >
                                    {{ link }}
                                    <ExternalLink class="size-3 shrink-0" />
                                </a>
                                <span v-else class="break-all">{{ link }}</span>
                            </li>
                        </ul>
                    </div>
                </div>
            </template>
        </SheetContent>
    </Sheet>
</template>
