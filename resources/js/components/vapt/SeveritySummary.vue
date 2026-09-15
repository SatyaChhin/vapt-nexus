<script setup lang="ts">
import { computed } from 'vue';
import { cn } from '@/lib/utils';
import type { SeverityCounts, SeverityKey } from '@/types';
import { SEVERITIES, SEVERITY_STYLES } from './severity';
import SeverityBadge from './SeverityBadge.vue';

const props = defineProps<{ counts: SeverityCounts; title?: string }>();

const total = computed(() =>
    SEVERITIES.reduce((sum, key) => sum + (props.counts[key] ?? 0), 0),
);

/** Findings above informational, i.e. the ones that need fixing. */
const actionable = computed(() => total.value - (props.counts.info ?? 0));

const highest = computed(() =>
    SEVERITIES.find((key) => key !== 'info' && props.counts[key] > 0),
);

function percent(key: SeverityKey): string {
    if (total.value === 0 || props.counts[key] === 0) {
        return '0%';
    }

    const value = (props.counts[key] / total.value) * 100;

    return value < 1 ? '<1%' : `${Math.round(value)}%`;
}

const summary = computed(() =>
    SEVERITIES.map(
        (key) => `${SEVERITY_STYLES[key].label}: ${props.counts[key]}`,
    ).join(', '),
);
</script>

<template>
    <section class="rounded-xl border p-4 sm:p-5">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h3 class="text-sm font-medium">
                    {{ title ?? 'Open findings by severity' }}
                </h3>
                <p class="text-muted-foreground mt-0.5 text-xs">
                    <template v-if="total === 0">No open findings.</template>
                    <template v-else-if="actionable === 0">
                        Nothing to fix. All {{ total }} findings are
                        informational.
                    </template>
                    <template v-else>
                        <span class="text-foreground font-medium tabular-nums">
                            {{ actionable }}
                        </span>
                        of {{ total }} need fixing ·
                        {{ counts.info }} informational
                    </template>
                </p>
            </div>

            <div class="flex items-center gap-2 text-xs">
                <template v-if="highest">
                    <span class="text-muted-foreground">Highest</span>
                    <SeverityBadge :severity="highest" />
                </template>
                <span
                    class="bg-muted text-foreground rounded-md px-2 py-0.5 font-medium tabular-nums"
                >
                    {{ total }} total
                </span>
                <slot name="actions" />
            </div>
        </div>

        <!-- Stacked distribution bar -->
        <div
            class="bg-muted mt-4 flex h-2.5 gap-0.5 overflow-hidden rounded-full"
            role="img"
            :aria-label="`Severity distribution. ${summary}`"
        >
            <template v-if="total > 0">
                <div
                    v-for="key in SEVERITIES.filter((k) => counts[k] > 0)"
                    :key="key"
                    :class="
                        cn(
                            'h-full min-w-1.5 transition-all duration-500',
                            SEVERITY_STYLES[key].bar,
                        )
                    "
                    :style="{ flexGrow: counts[key], flexBasis: 0 }"
                    :title="`${SEVERITY_STYLES[key].label}: ${counts[key]} (${percent(key)})`"
                />
            </template>
        </div>

        <!-- Per-severity tiles -->
        <dl class="mt-4 grid grid-cols-2 gap-2 sm:grid-cols-3 lg:grid-cols-5">
            <div
                v-for="key in SEVERITIES"
                :key="key"
                :class="
                    cn(
                        'rounded-lg border px-3 py-2.5 transition-colors',
                        counts[key] > 0
                            ? SEVERITY_STYLES[key].soft
                            : 'bg-muted/40 border-dashed',
                    )
                "
            >
                <dt
                    class="text-muted-foreground flex items-center gap-1.5 text-xs font-medium"
                >
                    <span
                        :class="
                            cn(
                                'size-2 rounded-full',
                                SEVERITY_STYLES[key].bar,
                                counts[key] === 0 && 'opacity-40',
                            )
                        "
                        aria-hidden="true"
                    />
                    {{ SEVERITY_STYLES[key].label }}
                </dt>
                <dd class="mt-1 flex items-baseline justify-between gap-2">
                    <span
                        :class="
                            cn(
                                'text-2xl leading-none font-semibold tabular-nums',
                                counts[key] > 0
                                    ? SEVERITY_STYLES[key].text
                                    : 'text-muted-foreground',
                            )
                        "
                    >
                        {{ counts[key] }}
                    </span>
                    <span class="text-muted-foreground text-xs tabular-nums">
                        {{ percent(key) }}
                    </span>
                </dd>
                <div
                    class="bg-foreground/5 mt-2 h-1 overflow-hidden rounded-full"
                    aria-hidden="true"
                >
                    <div
                        :class="
                            cn(
                                'h-full rounded-full transition-all duration-500',
                                SEVERITY_STYLES[key].bar,
                            )
                        "
                        :style="{
                            width:
                                total > 0
                                    ? `${(counts[key] / total) * 100}%`
                                    : '0%',
                        }"
                    />
                </div>
            </div>
        </dl>
    </section>
</template>
