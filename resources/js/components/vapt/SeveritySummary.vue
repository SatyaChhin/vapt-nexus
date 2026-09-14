<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { SeverityCounts } from '@/types';
import { SEVERITIES, SEVERITY_STYLES } from './severity';

const props = defineProps<{ counts: SeverityCounts; title?: string }>();

const total = computed(() =>
    SEVERITIES.reduce((sum, key) => sum + (props.counts[key] ?? 0), 0),
);
</script>

<template>
    <section class="rounded-xl border p-4">
        <div class="mb-3 flex items-baseline justify-between gap-2">
            <h3 class="text-sm font-medium">
                {{ title ?? 'Open findings by severity' }}
            </h3>
            <span class="text-muted-foreground text-xs">
                {{ total }} total
            </span>
        </div>

        <div
            class="bg-muted mb-4 flex h-2 overflow-hidden rounded-full"
            role="img"
            :aria-label="`Severity distribution of ${total} findings`"
        >
            <template v-if="total > 0">
                <div
                    v-for="key in SEVERITIES"
                    :key="key"
                    :class="SEVERITY_STYLES[key].bar"
                    :style="{ width: `${(counts[key] / total) * 100}%` }"
                />
            </template>
        </div>

        <dl class="grid grid-cols-5 gap-2">
            <div v-for="key in SEVERITIES" :key="key" class="text-center">
                <dt class="text-muted-foreground text-xs">
                    {{ SEVERITY_STYLES[key].label }}
                </dt>
                <dd
                    :class="
                        cn(
                            'mt-1 text-2xl font-semibold tabular-nums',
                            counts[key] === 0 && 'text-muted-foreground/60',
                        )
                    "
                >
                    {{ counts[key] }}
                </dd>
            </div>
        </dl>
    </section>
</template>
