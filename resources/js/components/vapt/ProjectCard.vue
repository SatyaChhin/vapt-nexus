<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import { Badge } from '@/components/ui/badge';
import { titleCase } from '@/lib/format';
import { show } from '@/routes/projects';
import type { Project } from '@/types';

defineProps<{ project: Project }>();
</script>

<template>
    <Link
        :href="show(project.id)"
        class="hover:border-foreground/20 focus-visible:ring-ring/50 flex flex-col rounded-xl border p-4 transition-colors outline-none focus-visible:ring-[3px]"
    >
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p
                    class="text-muted-foreground font-mono text-xs tracking-wide"
                >
                    {{ project.code }}
                </p>
                <h3 class="truncate font-semibold">{{ project.name }}</h3>
            </div>
            <Badge
                v-if="project.status !== 'active'"
                variant="secondary"
                class="shrink-0"
            >
                {{ titleCase(project.status) }}
            </Badge>
        </div>

        <p
            v-if="project.description"
            class="text-muted-foreground mt-2 line-clamp-2 text-sm"
        >
            {{ project.description }}
        </p>

        <dl class="mt-auto grid grid-cols-3 gap-2 pt-4 text-center text-sm">
            <div>
                <dt class="text-muted-foreground text-xs">Assets</dt>
                <dd class="font-semibold tabular-nums">
                    {{ project.assets_count ?? 0 }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-xs">Scans</dt>
                <dd class="font-semibold tabular-nums">
                    {{ project.scans_count ?? 0 }}
                </dd>
            </div>
            <div>
                <dt class="text-muted-foreground text-xs">Open findings</dt>
                <dd class="font-semibold tabular-nums">
                    {{ project.open_findings_count ?? 0 }}
                </dd>
            </div>
        </dl>
    </Link>
</template>
